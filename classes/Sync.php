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
     * The folder to sync into: the configured id, else the remembered one if it
     * still exists outside the trash, else "Grav backups (<site>)" in My Drive.
     */
    public static function folder(Drive $drive, string $configured, string $remembered, string $site): string
    {
        if ($configured !== '') {
            return $configured;
        }
        if ($remembered !== '') {
            try {
                if (empty($drive->request('GET', '/files/' . rawurlencode($remembered), ['fields' => 'id,trashed'])['trashed'])) {
                    return $remembered;
                }
            } catch (DriveException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }

        return $drive->ensureFolder("Grav backups ({$site})");
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
