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
        if (!empty($s['errors'])) {
            $lines[] = '';
        }
        foreach ((array) ($s['errors'] ?? []) as $err) {
            $lines[] = '- ' . $e($err);
        }

        return implode("\n", $lines);
    }
}
