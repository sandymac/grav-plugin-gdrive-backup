<?php

declare(strict_types=1);

namespace Grav\Plugin\GdriveBackup;

use Grav\Plugin\Gdrive\Drive;
use Grav\Plugin\Gdrive\DriveException;

/**
 * One pass of "make Drive match retention". Takes a Drive client and the
 * local backups, so the smoke test can drive it with a fake transport.
 *
 * Invariants: only files tagged grav_backup=1 are ever candidates; starred
 * files are never trashed; nothing is ever permanently deleted; an upload
 * whose md5 doesn't match the local file is trashed and reported.
 */
final class Sync
{
    public const TAG = 'grav_backup';
    public const FIELDS = 'id,name,size,md5Checksum,starred,appProperties,createdTime';

    /** @param array{days?: int, weeks?: int, months?: int, years?: int} $generational */
    public function __construct(
        private Drive $drive,
        private string $folderId,
        private string $site,
        private int $keep,
        private array $generational,
        private ?int $now = null,
    ) {
    }

    /**
     * @param array<string, string> $local filename => path of the local backups
     * @return array{uploaded: string[], trashed: string[], errors: string[], drive_count: int}
     */
    public function run(array $local): array
    {
        $out = ['uploaded' => [], 'trashed' => [], 'errors' => [], 'drive_count' => 0];

        // filename => Drive files with that name; untagged files never enter (belt and braces over the query).
        $remote = [];
        foreach ($this->drive->findByAppProperty($this->folderId, self::TAG, '1', self::FIELDS) as $f) {
            if (($f['appProperties'][self::TAG] ?? null) === '1') {
                $remote[(string) $f['name']][] = $f;
            }
        }

        // Retention over the union, so a backup already rotated off Drive isn't re-uploaded
        // from disk. A starred copy takes its name out of the count entirely.
        $times = [];
        foreach (array_keys($local + $remote) as $name) {
            $ts = Retention::timestamp((string) $name);
            $starred = array_filter($remote[$name] ?? [], static fn (array $f): bool => !empty($f['starred'])) !== [];
            if ($ts !== null && !$starred) {
                $times[$name] = $ts;
            }
        }
        $kept = array_flip(Retention::keep(array_values($times), $this->keep, $this->generational, $this->now));
        $survives = static fn (string $name): bool => isset($times[$name]) && isset($kept[$times[$name]]);

        $count = array_sum(array_map('count', $remote));
        foreach ($local as $name => $path) {
            if (!$survives($name) || isset($remote[$name])) {
                continue;
            }
            try {
                $file = $this->drive->upload($path, $this->folderId, $name, [self::TAG => '1', 'site' => $this->site], 'id,name,md5Checksum,size');
                if (($file['md5Checksum'] ?? null) !== md5_file($path)) {
                    $out['errors'][] = "{$name}: md5 mismatch after upload, the upload was trashed";
                    $this->drive->trash((string) $file['id']);
                    continue;
                }
                $out['uploaded'][] = $name;
                $count++;
            } catch (DriveException $e) {
                $out['errors'][] = "{$name}: " . $e->getMessage();
            }
        }

        // Don't rotate old copies away while a newer one failed to land.
        if ($out['errors'] !== []) {
            $out['errors'][] = 'retention skipped this run because an upload failed';
        } else {
            foreach ($remote as $name => $files) {
                if (!isset($times[$name]) || $survives((string) $name)) {
                    continue; // starred, unparseable, or kept
                }
                foreach ($files as $f) {
                    try {
                        $this->drive->trash((string) $f['id']);
                        $out['trashed'][] = (string) $name;
                        $count--;
                    } catch (DriveException $e) {
                        $out['errors'][] = "{$name}: " . $e->getMessage();
                    }
                }
            }
        }
        $out['drive_count'] = $count;

        return $out;
    }

    /** The name of the folder the plugin creates when none is configured. Pure; the settings help shows it too. */
    public static function folderName(string $site): string
    {
        return "Grav backups ({$site})";
    }

    /**
     * The folder id from what the owner pasted: a bare id, or a Drive link such as
     * https://drive.google.com/drive/u/0/folders/<id>?usp=sharing or .../open?id=<id>.
     * Anything else comes back trimmed, so Drive's notFound names the problem. Pure.
     */
    public static function folderId(string $input): string
    {
        $input = trim($input);
        if (preg_match('~/folders/([A-Za-z0-9_-]+)~', $input, $m) === 1 || preg_match('~[?&]id=([A-Za-z0-9_-]+)~', $input, $m) === 1) {
            return $m[1];
        }

        return $input;
    }

    /**
     * The folder memories kept in status.json. An old status (before 0.1.5) had
     * only folder_id, which in blank-setting mode was the plugin's own folder;
     * with a folder set it was the configured one and must not be reused later.
     *
     * @return array{auto_folder_id: string, replacement: ?array{for: string, id: string}}
     */
    public static function memory(array $status, string $configured): array
    {
        $rep = $status['replacement'] ?? null;

        return [
            'auto_folder_id' => array_key_exists('auto_folder_id', $status) ? (string) $status['auto_folder_id'] : ($configured === '' ? (string) ($status['folder_id'] ?? '') : ''),
            'replacement' => is_array($rep) && is_string($rep['for'] ?? null) && is_string($rep['id'] ?? null) ? ['for' => $rep['for'], 'id' => $rep['id']] : null,
        ];
    }

    /**
     * The folder to sync into this run. Blank setting: the plugin's own folder,
     * created on the first run. Set: the configured folder. If either is in the
     * trash or gone, $recreate makes a new one (a same-name sibling for a trashed
     * configured folder, else "Grav backups (<site>)" in My Drive) and remembers
     * it; otherwise the run stops. Never restores or deletes anything.
     *
     * @return array{id: string, status: array, warning: ?string}
     * @throws \RuntimeException (DriveException included) with a message for the owner: the run stops
     */
    public static function resolveFolder(Drive $drive, string $configured, array $status, string $site, bool $recreate, bool $serviceAccount = false, string $account = 'the account'): array
    {
        $status = [...$status, ...self::memory($status, $configured)];

        if ($configured === '') {
            $auto = $status['auto_folder_id'];
            if ($auto !== '' && empty(self::file($drive, $auto, 'id,trashed')['trashed'] ?? true)) {
                return ['id' => $auto, 'status' => $status, 'warning' => null];
            }
            if ($auto !== '' && !$recreate) {
                throw new \RuntimeException("The plugin's backup folder is in the trash or gone. Restore it, or turn on Recreate a missing folder.");
            }
            $status['auto_folder_id'] = $drive->ensureFolder(self::folderName($site));

            return ['id' => $status['auto_folder_id'], 'status' => $status, 'warning' => $auto === '' ? null : 'The backup folder was in the trash or gone; created a new one.'];
        }

        $f = self::file($drive, $configured, 'id,name,trashed,parents,driveId,capabilities(canAddChildren)');
        if ($f !== null && empty($f['trashed'])) {
            return ['id' => $configured, 'status' => ['replacement' => null] + $status, 'warning' => null];
        }
        $trashedWarning = 'Your configured folder is in the trash; backing up to the replacement. Update the Drive folder setting.';
        $rep = $status['replacement'];
        if ($rep !== null && $rep['for'] === $configured && empty(self::file($drive, $rep['id'], 'id,trashed')['trashed'] ?? true)) {
            return ['id' => $rep['id'], 'status' => $status, 'warning' => $f !== null ? $trashedWarning : "Your configured Drive folder couldn't be found (deleted, or no longer shared); backing up to the replacement. Update the Drive folder setting."];
        }
        if ($f !== null) {
            if (!$recreate) {
                throw new \RuntimeException('Your configured Drive folder is in the trash. Restore it or choose another, or turn on Recreate a missing folder.');
            }
            $parent = (string) ($f['parents'][0] ?? '');
            if ($parent !== '') {
                try {
                    $status['replacement'] = ['for' => $configured, 'id' => $drive->ensureFolder((string) $f['name'], $parent)];

                    return ['id' => $status['replacement']['id'], 'status' => $status, 'warning' => $trashedWarning];
                } catch (DriveException) {
                    // can't create beside it: fall back as if it were gone
                }
            }
        }
        if (!$recreate || $serviceAccount) {
            throw new \RuntimeException("Your configured Drive folder couldn't be found. It was deleted or is no longer shared with {$account}." . ($serviceAccount ? ' Service accounts have no My Drive to fall back to.' : ''));
        }
        $status['replacement'] = ['for' => $configured, 'id' => $drive->ensureFolder(self::folderName($site))];

        return ['id' => $status['replacement']['id'], 'status' => $status, 'warning' => "Your configured Drive folder couldn't be found (deleted, or no longer shared); backing up to '" . self::folderName($site) . "' in My Drive. Update the Drive folder setting."];
    }

    /** files.get, or null when Drive says 404 (gone, or not shared with this account). */
    private static function file(Drive $drive, string $id, string $fields): ?array
    {
        try {
            return $drive->request('GET', '/files/' . rawurlencode($id), ['fields' => $fields]);
        } catch (DriveException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Runs $fn under a non-blocking flock on $lockFile; returns null without
     * running it if another process holds the lock.
     *
     * @template T
     * @param callable(): T $fn
     * @return T|null
     */
    public static function locked(string $lockFile, callable $fn): mixed
    {
        $fh = @fopen($lockFile, 'c');
        if ($fh === false) {
            throw new \RuntimeException("gdrive-backup: cannot open lock file {$lockFile}");
        }
        try {
            if (!flock($fh, LOCK_EX | LOCK_NB)) {
                return null;
            }
            try {
                return $fn();
            } finally {
                flock($fh, LOCK_UN);
            }
        } finally {
            fclose($fh);
        }
    }
}
