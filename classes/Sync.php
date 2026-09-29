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
    /** What the settings page's folder check asks of a configured folder. */
    public const CHECK_FIELDS = 'id,name,trashed,parents,driveId,capabilities(canAddChildren,canTrashChildren)';
    /** Both the sync's stop and the settings line: a service account's uploads to My Drive fail with storageQuotaExceeded. */
    public const SA_MY_DRIVE = 'This folder is in My Drive. A service account has no storage there, so Google refuses uploads. Use a folder in a Shared Drive, shared with the service account as Content manager, or use an OAuth account.';

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
     * @return array{uploaded: string[], trashed: string[], errors: string[], warnings: string[], drive_count: int}
     */
    public function run(array $local): array
    {
        $out = ['uploaded' => [], 'trashed' => [], 'errors' => [], 'warnings' => [], 'drive_count' => 0];

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
            if (!$survives($name)) {
                continue;
            }
            $md5 = null; // hashed at most once, and only when needed: these zips are tens of MB
            $hash = static function () use (&$md5, $path): string {
                return $md5 ??= (string) md5_file($path);
            };
            // A same-name copy whose checksum differs isn't a good copy: replace it. No checksum on Drive: can't tell, leave it.
            $bad = array_filter($remote[$name] ?? [], static fn (array $f): bool => isset($f['md5Checksum']) && $f['md5Checksum'] !== $hash());
            if (isset($remote[$name]) && count($bad) < count($remote[$name])) {
                continue;
            }
            try {
                $file = $this->drive->upload($path, $this->folderId, $name, [self::TAG => '1', 'site' => $this->site], 'id,name,md5Checksum,size');
                $sum = $file['md5Checksum'] ?? (self::file($this->drive, (string) $file['id'], 'md5Checksum') ?? [])['md5Checksum'] ?? null;
                if ($sum !== null && $sum !== $hash()) {
                    $out['errors'][] = "{$name}: md5 mismatch after upload, the upload was trashed";
                    $this->drive->trash((string) $file['id']);
                    continue;
                }
                $out['uploaded'][] = $name;
                $count++;
                if ($sum === null) {
                    $out['warnings'][] = "Drive didn't report a checksum for {$name}, so it couldn't be verified.";
                    continue; // unverified: keep any old copy too
                }
                foreach ($bad as $f) {
                    if (empty($f['starred'])) {
                        $this->drive->trash((string) $f['id']);
                        $out['trashed'][] = $name;
                        $count--;
                    }
                }
                if ($bad !== []) {
                    $out['warnings'][] = "{$name} on Drive didn't match the local file, so it was uploaded again and the bad copy moved to the trash.";
                }
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
     * Keep in sync with parseFolder() in admin-next/fields/gdrive-folder.js (the settings hint).
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
     * trash or gone, $recreate makes a new one (a new same-name sibling, or
     * "<name> (2)"…, for a trashed configured folder, else "Grav backups (<site>)"
     * in My Drive) and remembers it; otherwise the run stops. A service account's
     * configured folder must be in a Shared Drive. Once the configured folder is
     * back, the replacement is remembered (with a warning) only while it still
     * holds backups. Never restores or deletes anything.
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
        if ($f !== null && $serviceAccount && (string) ($f['driveId'] ?? '') === '') {
            throw new \RuntimeException(self::SA_MY_DRIVE); // trashed too: a replacement beside it would be in My Drive as well
        }
        if ($f !== null && empty($f['trashed'])) {
            // The folder is back: use it (it's what the setting says), but keep pointing at a replacement
            // that still holds backups until it's trashed, gone or emptied of them.
            try {
                $left = self::leftover($drive, $status['replacement'], $configured);
                $status['replacement'] = $left !== null ? $status['replacement'] : null;
            } catch (DriveException) {
                $left = null; // a hiccup on a reminder mustn't stop the backup; check again next run
            }

            return ['id' => $configured, 'status' => $status, 'warning' => $left !== null ? self::backWarning($left) : null];
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
                    $status['replacement'] = ['for' => $configured, 'id' => self::newFolder($drive, (string) $f['name'], $parent)];

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

    /**
     * The remembered replacement for $configured (files.get id,name,trashed) while
     * it's alive and still holds tagged backups; null once it's trashed, gone or
     * empty of them, or if it was made for another folder.
     *
     * @param ?array{for: string, id: string} $rep memory()'s replacement
     * @internal also used by the settings page's folder check
     */
    public static function leftover(Drive $drive, ?array $rep, string $configured): ?array
    {
        if ($rep === null || $rep['for'] !== $configured) {
            return null;
        }
        $f = self::file($drive, $rep['id'], 'id,name,trashed');

        return $f !== null && empty($f['trashed']) && $drive->findByAppProperty($rep['id'], self::TAG, '1', 'id') !== [] ? $f : null;
    }

    /** The "your folder is back" reminder, for the sync's warning and the settings line alike. Pure. */
    private static function backWarning(array $rep): string
    {
        $id = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($rep['id'] ?? ''));

        return 'Your folder is back. Backups made while it was missing are still in "' . self::clean((string) ($rep['name'] ?? '')) . "\" ([open it](https://drive.google.com/drive/folders/{$id}))."
            . " Move them into your folder, or trash that folder once you don't need them.";
    }

    /**
     * Always a new folder in $parent, never an existing one (which may be someone
     * else's): "<name>", or "<name> (2)", (3)… if a live folder there has that name.
     */
    private static function newFolder(Drive $drive, string $name, string $parent): string
    {
        $taken = array_flip(array_map(static fn (array $f): string => (string) ($f['name'] ?? ''), $drive->children($parent, [], 'name')));
        // ponytail: bounded at (20); past that Drive gets a duplicate name, which it allows.
        for ($n = 1, $try = $name; isset($taken[$try]) && $n < 20; $try = $name . ' (' . ++$n . ')') {
        }

        return (string) $drive->request('POST', '/files', ['fields' => 'id'], ['name' => $try, 'mimeType' => Drive::FOLDER, 'parents' => [$parent]])['id'];
    }

    /**
     * The settings page's one-line folder check: what the next resolveFolder()
     * will do, from lookups already made, changing nothing. Pure; it mirrors
     * resolveFolder() branch for branch, so change the two together.
     *
     * @param array{auto_folder_id: string, replacement: ?array{for: string, id: string}} $memory from memory()
     * @param ?array $file files.get (CHECK_FIELDS) of $configured, or when blank of auto_folder_id; null for 404 or not looked up
     * @param ?array $replacement memory's replacement: files.get while the configured folder is missing, leftover()
     *                            while it's alive (so only while it still holds backups); null for none or 404
     * @param string $driveName the folder's Shared Drive name, '' if unknown
     * @param string $email the account's Google identity ('' falls back to $account)
     * @param ?\Throwable $error what the lookups (or the account) threw instead
     * @param string $authUrl the Google Drive Auth settings page
     * @return array{0: string, 1: string} [✔|⚠|✘|•, Markdown with every outside value cleaned]
     */
    public static function folderVerdict(string $configured, array $memory, ?array $file, ?array $replacement, string $driveName, bool $recreate, bool $serviceAccount, string $account, string $email, string $site, ?\Throwable $error = null, string $authUrl = ''): array
    {
        $q = static fn (mixed $s): string => '"' . self::clean((string) $s) . '"';
        $who = '`' . str_replace(['`', "\r", "\n", '<', '>'], '', $email !== '' ? $email : $account) . '`';
        $own = $q(self::folderName($site));
        $auth = $authUrl !== '' ? "[Google Drive Auth]({$authUrl})" : '**Google Drive Auth**';

        if ($configured === '' && $serviceAccount) {
            return ['✘', "Service accounts have no My Drive: set a folder in a Shared Drive shared with {$who} as **Content manager**."];
        }
        if ($error !== null) {
            $reason = $error instanceof DriveException ? $error->reason : '';
            $anchor = $error instanceof DriveException ? $error->anchor() : '';
            $message = self::clean((string) preg_replace('/^gdrive:\s*/', '', $error->getMessage()));

            return match ($reason) {
                'transport' => ['•', "Couldn't reach Google to check the folder right now."],
                'scope_not_granted' => ['✘', ($configured === '' ? 'Needs `drive.file` access' : 'Needs `drive` access to use a folder you picked') . ": click **Reconnect** on {$auth}."],
                'not_connected' => ['✘', "The account isn't connected yet: click **Connect** on {$auth}."],
                'unknown_account' => ['✘', 'No account named **' . self::clean($account) . "** yet: add it on {$auth}."],
                default => ['✘', "Couldn't check the folder: " . (mb_strlen($message) > 160 ? mb_substr($message, 0, 159) . '…' : $message)
                    . ($anchor !== '' && $authUrl !== '' ? " ([how to fix]({$authUrl}#troubleshooting--{$anchor}))" : '')],
            };
        }

        if ($configured === '') {
            if ($memory['auto_folder_id'] === '') {
                return ['✔', "Will create {$own} in {$who}'s My Drive on the first sync."];
            }
            if ($file !== null && empty($file['trashed'])) {
                return ['✔', 'Backing up to ' . $q($file['name'] ?? '') . ' in My Drive.'];
            }

            return $recreate
                ? ['⚠', 'The backup folder is in the trash or gone; the next sync creates a new one.']
                : ['✘', 'The backup folder is in the trash or gone; the next sync will stop. Restore it or turn on **Recreate a missing folder**.'];
        }

        $shared = (string) ($file['driveId'] ?? '') !== '';
        if ($file !== null && $serviceAccount && !$shared) {
            return ['✘', self::SA_MY_DRIVE];
        }
        $rep = $memory['replacement'];
        $repAlive = $rep !== null && $rep['for'] === $configured && $replacement !== null && empty($replacement['trashed']);
        if ($file !== null && empty($file['trashed'])) {
            $caps = (array) ($file['capabilities'] ?? []);
            $name = $q($file['name'] ?? '');
            if (($caps['canAddChildren'] ?? null) === false) {
                return ['✘', "This account can only view {$name}. Share it as **Editor** (My Drive) or **Content manager** (Shared Drive)."];
            }
            // Only in a Shared Drive: in My Drive the account owns what it uploads, so it can trash those regardless.
            if ($shared && ($caps['canTrashChildren'] ?? null) === false) {
                return ['⚠', "Uploads will work, but old copies can't be moved to the trash (retention). Share it as **Content manager**."];
            }
            if ($repAlive) {
                return ['⚠', self::backWarning($replacement)];
            }
            $where = !$shared ? 'in My Drive' : ($driveName !== '' ? 'in Shared Drive ' . $q($driveName) : 'in a Shared Drive');

            return ['✔', "{$name} {$where}: can add and remove backups."];
        }

        $lead = $file !== null ? 'Your folder is in the trash' : "{$who} can't see that folder";
        if ($repAlive) {
            return ['⚠', "{$lead}; backups go to the replacement " . $q($replacement['name'] ?? '') . '. Update the Drive folder setting.'];
        }
        $fallback = $recreate && !$serviceAccount ? "the next sync backs up to {$own} in My Drive instead." : 'the next sync will stop.';
        if ($file !== null) {
            if (!$recreate) {
                return ['✘', "{$lead}; the next sync will stop. Restore it or choose another, or turn on **Recreate a missing folder**."];
            }
            if ((string) ($file['parents'][0] ?? '') !== '') {
                return ['⚠', 'Your folder is in the trash. The next sync creates a new folder beside it with the same name (or with " (2)" if that name is taken).'];
            }

            return [$serviceAccount ? '✘' : '⚠', "{$lead}; {$fallback}"]; // nowhere to put one beside it: as if gone
        }

        return ['✘', "{$lead}: check the link, and share it with that account; {$fallback}"];
    }

    /** A value from Drive or config going into Markdown as text: no markup, no code-span or link breakouts. Pure. */
    private static function clean(string $s): string
    {
        return trim((string) preg_replace('/[<>`\[\]*_|\\\\\r\n]/', '', $s));
    }

    /**
     * files.get, or null when Drive says 404 (gone, or not shared with this account).
     *
     * @internal also used by the settings page's folder check
     */
    public static function file(Drive $drive, string $id, string $fields): ?array
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
