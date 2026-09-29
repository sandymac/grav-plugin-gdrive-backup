<?php

declare(strict_types=1);

namespace Grav\Plugin\GdriveBackup;

use Grav\Common\Grav;

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

    /** data-content@ renderer for the blueprint's "Last sync" display field. */
    public static function markdown(): string
    {
        $config = Grav::instance()['config'];
        $lines = [];
        $account = (string) $config->get('plugins.gdrive-backup.account', 'personal');
        if ((string) $config->get('plugins.gdrive-backup.folder', '') === '' && $config->get("plugins.gdrive.accounts.{$account}.type") === 'service_account') {
            $lines[] = "**Warning:** `{$account}` is a service account, which has no My Drive to create a folder in. Set **Drive folder** to a folder in a Shared Drive shared with it as **Content Manager**, or use an OAuth account.";
            $lines[] = '';
        }

        $s = self::read();
        if ($s === []) {
            $lines[] = 'No sync has run yet. It runs after each scheduled backup and on the sync schedule below.';

            return implode("\n", $lines);
        }
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
        $lines[] = sprintf(
            '**Last sync:** %s, %s. Uploaded %d, trashed %d, %d backup(s) on Drive (folder `%s`).',
            $e($s['last_run'] ?? '?'),
            empty($s['ok']) ? '**failed**' : 'OK',
            count((array) ($s['uploaded'] ?? [])),
            count((array) ($s['trashed'] ?? [])),
            (int) ($s['drive_count'] ?? 0),
            $e($s['folder_id'] ?? ''),
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
