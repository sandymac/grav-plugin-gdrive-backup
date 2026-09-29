<?php

declare(strict_types=1);

namespace Grav\Plugin\GdriveBackup;

use Grav\Common\Grav;
use Grav\Plugin\Gdrive\Drive;
use Grav\Plugin\Gdrive\DriveException;
use Grav\Plugin\Gdrive\Gdrive;
use Grav\Plugin\Gdrive\Http;
use Grav\Plugin\Gdrive\OAuthUser;

/** user/data/gdrive-backup/status.json, and its Markdown for the settings page. No secrets in either. */
final class Status
{
    /** user/data/gdrive-backup, created if missing. */
    public static function dir(): string
    {
        $dir = (string) Grav::instance()['locator']->findResource('user://data/gdrive-backup', true, true);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function read(): array
    {
        $data = json_decode((string) @file_get_contents(self::dir() . '/status.json'), true);

        return is_array($data) ? $data : [];
    }

    public static function write(array $status): void
    {
        $file = self::dir() . '/status.json';
        file_put_contents($file . '.tmp', (string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($file . '.tmp', $file);
    }

    /**
     * The site name for the folder and the `site` tag. The scheduler runs in the
     * CLI, where the request host is meaningless, so prefer custom_base_url; the
     * settings help uses this too, so it names the folder the sync will create.
     */
    public static function site(): string
    {
        $host = parse_url((string) Grav::instance()['config']->get('system.custom_base_url', ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : (string) gethostname();
    }

    /** data-help@ for the folder field, with this site's real folder name in it. */
    public static function folderHelp(): string
    {
        try {
            $name = '"' . Sync::folderName(self::site()) . '"';
        } catch (\Throwable) {
            $name = 'a "Grav backups" folder named after this site';
        }

        return "Empty: the plugin creates its own {$name} folder in the account's My Drive (OAuth accounts only; needs just drive.file)."
            . " Set: paste the folder's link from Drive's address bar or its Share dialog, or just its ID; this needs full Drive access."
            . ' Service accounts have no My Drive, so they must use a folder in a Shared Drive, shared with the service account as Content Manager.';
    }

    /**
     * The backup profiles whose scheduler job is on, as [name, schedule_at]. Same rule
     * as Grav core: an Enabled/Disabled toggle in scheduler.status wins, else the
     * profile's own `schedule` flag. Pure.
     *
     * @param array<int, array<string, mixed>> $profiles backups.profiles
     * @param array<string, mixed> $toggles scheduler.status
     * @param callable(string): string $jobName profile name → scheduler job id (Grav's Inflector::hyphenize)
     * @return array<int, array{0: string, 1: string}>
     */
    public static function activeProfiles(array $profiles, array $toggles, callable $jobName): array
    {
        $active = [];
        foreach ($profiles as $p) {
            $name = (string) ($p['name'] ?? '');
            $job = $jobName($name);
            if (isset($toggles[$job]) ? $toggles[$job] !== 'disabled' : !empty($p['schedule'])) {
                $active[] = [$name, (string) ($p['schedule_at'] ?? '')];
            }
        }

        return $active;
    }

    /**
     * "Name (`0 3 * * *`), …" for the notice. Names go in as text (no markup);
     * schedules go in a code span, where only a backtick could break out. Pure.
     *
     * @param array<int, array{0: string, 1: string}> $active
     */
    public static function profileList(array $active): string
    {
        return implode(', ', array_map(static fn (array $a): string => preg_replace('/[<>`|*_\[\]\r\n]/', '', $a[0])
            . ($a[1] !== '' ? ' (`' . preg_replace('/[`\r\n]/', '', $a[1]) . '`)' : ''), $active));
    }

    /**
     * Whether a backup profile's zip holds user/data/gdrive (the site's Google
     * refresh token, client secret, service-account key). Same matching as Grav's
     * Backups::convertExclude() and RecursiveDirectoryFilterIterator: paths are
     * relative to the profile root, split on newlines (CRLF too), commas or 2+
     * spaces, slashes trimmed, and a path excludes everything under it. Pure.
     */
    public static function includesSignIn(string $root, string $excludePaths): bool
    {
        $rel = 'user/data/gdrive';
        $root = trim(str_replace('user://', 'user/', trim($root)), '/');
        if ($root !== '') {
            if ($rel !== $root && !str_starts_with($rel, $root . '/')) {
                return false; // the profile backs up somewhere else
            }
            $rel = ltrim(substr($rel, strlen($root)), '/');
        }
        foreach (preg_split('/[\r\n,]+|\s{2,}/', $excludePaths) ?: [] as $p) {
            $p = trim(trim($p), '/');
            if ($p !== '' && ($rel === $p || str_starts_with($rel, $p . '/'))) {
                return false;
            }
        }

        return true;
    }

    /** data-content@ for the line saying Grav itself makes the backups, and where that's set up. */
    public static function profilesNotice(): string
    {
        $where = '**Configuration → Backups**';
        try {
            $grav = Grav::instance();
            $where = sprintf('[%s](%s)', $where, self::adminUrl('config/backups'));
            $profiles = array_values((array) $grav['config']->get('backups.profiles', []));
            $active = self::activeProfiles($profiles, (array) $grav['config']->get('scheduler.status', []), [\Grav\Common\Inflector::class, 'hyphenize']);
        } catch (\Throwable) {
            return "Grav makes the backups; this plugin uploads them. Set up what's backed up, and when, in {$where}.";
        }
        $list = self::profileList($active);
        $count = count($profiles);
        $state = match (true) {
            $count === 0 => 'There are no backup profiles yet.',
            $active === [] => sprintf('None of the %d backup profile%s is scheduled, so only backups made with **Backup now** reach Drive.', $count, $count === 1 ? '' : 's'),
            default => sprintf('**%d of %d** backup profile%s scheduled: %s.', count($active), $count, count($active) === 1 ? ' is' : 's are', $list),
        };

        // The scheduled profiles; with none, any of them via Backup now.
        $names = array_flip(array_column($active, 0));
        $exposed = array_filter($profiles, static fn (mixed $p): bool => is_array($p) && ($active === [] || isset($names[(string) ($p['name'] ?? '')]))
            && self::includesSignIn((string) ($p['root'] ?? '/'), (string) ($p['exclude_paths'] ?? '')));
        $signIn = $exposed === [] ? '' : "\n\n⚠ These backups include this site's Google sign-in (`user/data/gdrive/`), so anyone who can open a backup zip can reach your Google Drive."
            . " Keep the Drive folder private, or add `/user/data/gdrive` to the profile's **Exclude paths** in {$where} (you'd then reconnect Google after restoring).";

        return "Grav makes the backups; this plugin uploads them. Set up what's backed up, and when, in {$where}. {$state}{$signIn}";
    }

    /**
     * data-content@ for the line under Drive folder: what the next sync will do
     * with the saved settings. Runs on every settings-page load, so: 5 s in all
     * and no retries (token refresh included), cached 5 minutes on everything the
     * verdict depends on (a sync that changes the folder changes the key), and
     * any failure is a neutral line, never a broken page.
     */
    public static function folderCheck(): string
    {
        try {
            $line = self::checkLine();
        } catch (\Throwable) {
            $line = "• Couldn't check the folder right now.";
        }

        return (string) preg_replace('/<(?=\s*(script|div\s+id\s*=))/i', '&lt;', $line);
    }

    private static function checkLine(): string
    {
        if (!class_exists(Drive::class)) {
            return '• Install and enable **Google Drive Auth** to check the folder.';
        }
        $grav = Grav::instance();
        $config = $grav['config'];
        $account = (string) $config->get('plugins.gdrive-backup.account', 'personal');
        $configured = Sync::folderId((string) $config->get('plugins.gdrive-backup.folder', ''));
        $recreate = (bool) $config->get('plugins.gdrive-backup.recreate_folder', true);
        $sa = $config->get("plugins.gdrive.accounts.{$account}.type") === 'service_account';
        $memory = Sync::memory(self::read(), $configured);
        $site = self::site();
        $scopes = [$configured === '' ? Drive::SCOPE_FILE : Drive::SCOPE_FULL];
        $authUrl = self::adminUrl('plugins/gdrive');
        $accounts = Gdrive::accounts();
        try {
            $state = $accounts->status($account); // local only: connected, email, granted scopes
        } catch (DriveException $e) {
            $state = $e->reason;
        }

        $cache = $grav['cache'];
        $key = 'gdrive-backup.folder-check.' . sha1((string) json_encode([$account, $configured, $recreate, $sa, $memory, $site, $state, $authUrl]));
        $hit = $cache->fetch($key);
        if (is_string($hit) && $hit !== '') {
            return $hit;
        }

        $deadline = microtime(true) + 5;
        $http = static function (string $method, string $url, array $opts = []) use ($deadline): array {
            $left = (int) ceil($deadline - microtime(true));
            if ($left <= 0) {
                throw new DriveException('gdrive: folder check out of time', 'transport');
            }

            return Http::curl($method, $url, ['timeout' => $left] + $opts);
        };
        $file = $replacement = $error = null;
        $email = $driveName = '';
        try {
            $creds = $accounts->withHttp($http)->credentials($account);
            $email = $creds->email();
            if ($creds instanceof OAuthUser && !(is_array($state) && $state['connected'])) {
                throw new DriveException('', 'not_connected');
            }
            if ($creds instanceof OAuthUser && OAuthUser::missingScopes($creds->granted(), $scopes) !== []) {
                throw new DriveException('', 'scope_not_granted');
            }
            $drive = new Drive($creds, $scopes, $http);
            $id = $configured !== '' ? $configured : ($sa ? '' : $memory['auto_folder_id']);
            if ($id !== '') {
                $file = Sync::file($drive, $id, $configured !== '' ? Sync::CHECK_FIELDS : 'id,name,trashed');
                $rep = $memory['replacement'];
                if ($configured !== '' && $rep !== null && $rep['for'] === $configured) { // same lookups as resolveFolder()
                    if ($file === null || !empty($file['trashed'])) {
                        $replacement = Sync::file($drive, $rep['id'], 'id,name,trashed');
                    } else {
                        try {
                            $replacement = Sync::leftover($drive, $rep, $configured);
                        } catch (DriveException) {
                            // resolveFolder() skips the reminder on a hiccup too
                        }
                    }
                }
                if ($file !== null && empty($file['trashed']) && (string) ($file['driveId'] ?? '') !== '') {
                    try {
                        $driveName = (string) ($drive->request('GET', '/drives/' . rawurlencode((string) $file['driveId']), ['fields' => 'name'])['name'] ?? '');
                    } catch (\RuntimeException) {
                        // the name is a nicety; "in a Shared Drive" will do
                    }
                }
            }
        } catch (DriveException $e) {
            $error = $e;
        }

        [$level, $text] = Sync::folderVerdict($configured, $memory, $file, $replacement, $driveName, $recreate, $sa, $account, $email, $site, $error, $authUrl);
        $line = "{$level} {$text}" . ($level === '✔' ? '' : ' _Checked when the page loads, from the saved settings._');
        if ($level !== '•') { // a Google hiccup shouldn't stick for 5 minutes
            $cache->save($key, $line, 300);
        }

        return $line;
    }

    /** An Admin2 page's URL, e.g. adminUrl('plugins/gdrive'). */
    private static function adminUrl(string $path): string
    {
        $grav = Grav::instance();
        $route = trim((string) $grav['config']->get('plugins.admin2.route', '/admin'), '/');

        return rtrim((string) $grav['uri']->rootUrl(false), '/') . "/{$route}/{$path}";
    }

    /**
     * "[folder](https://drive.google.com/drive/folders/<id>) `<id>`": the word links to
     * the folder and the id stays plain so it copies easily. Only Drive-id characters
     * reach the URL; anything else shows as plain "folder". Pure.
     * ponytail: same-tab link; Admin2 runs display fields through DOMPurify's defaults,
     * which strip target="_blank". Ctrl/Cmd-click opens it in a new tab.
     */
    public static function folderRef(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{10,}$/', $id) !== 1) {
            return 'folder';
        }

        return "[folder](https://drive.google.com/drive/folders/{$id}) `{$id}`";
    }

    /** data-content@ renderer for the blueprint's "Last sync" display field. */
    public static function markdown(): string
    {
        $lines = [];
        $s = self::read();
        if ($s === []) {
            $lines[] = 'No sync has run yet. It runs after each scheduled backup and on the sync schedule below.';

            return implode("\n", $lines);
        }
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $lines[] = sprintf(
            '**Last sync:** %s, %s. Uploaded %d, trashed %d, %d backup(s) on Drive (%s).',
            $e($s['last_run'] ?? '?'),
            empty($s['ok']) ? '**failed**' : 'OK',
            count((array) ($s['uploaded'] ?? [])),
            count((array) ($s['trashed'] ?? [])),
            (int) ($s['drive_count'] ?? 0),
            self::folderRef((string) ($s['folder_id'] ?? '')),
        );
        foreach ((array) ($s['warnings'] ?? []) as $w) {
            $lines[] = '';
            $lines[] = '**Warning:** ' . $e($w);
        }
        if (!empty($s['errors'])) {
            $lines[] = '';
        }
        foreach ((array) ($s['errors'] ?? []) as $err) {
            $lines[] = '- ' . $e($err);
        }

        return implode("\n", $lines);
    }
}
