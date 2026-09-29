<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Backup\Backups;
use Grav\Common\Plugin;
use Grav\Plugin\Gdrive\Drive;
use Grav\Plugin\Gdrive\Gdrive;
use Grav\Plugin\GdriveBackup\Status;
use Grav\Plugin\GdriveBackup\Sync;
use RocketTheme\Toolbox\Event\Event;

/**
 * Uploads Grav backups to Google Drive and applies retention there. Grav's own
 * backups.yaml purge still owns the LOCAL copies; this only manages Drive.
 */
class GdriveBackupPlugin extends Plugin
{
    public const VERSION = '0.1.4';
    public const JOB = 'gdrive-backup-sync';

    private static bool $warnedMissing = false;

    /**
     * Static on purpose: under Admin2 the api plugin sets $grav['admin'], so
     * isAdmin() is true on every API request, and these must fire regardless.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['autoload', 100000],
            'onSchedulerInitialized' => ['onSchedulerInitialized', 0],
            'onBackupFinished' => ['onBackupFinished', 0],
            'onGdriveScopes' => ['onGdriveScopes', 0],
        ];
    }

    public function autoload(): void
    {
        self::registerAutoload();
        // Admin2 may resolve data-*@ through the API's /data/resolve, which only calls allowlisted providers.
        \Grav\Common\Data\Blueprint::addAllowedDynamicCallable(Status::class . '::folderHelp');
    }

    /** Idempotent spl fallback so a git clone into user/plugins works without Composer. */
    private static function registerAutoload(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        spl_autoload_register(static function (string $class): void {
            $prefix = 'Grav\\Plugin\\GdriveBackup\\';
            if (str_starts_with($class, $prefix)) {
                $path = __DIR__ . '/classes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            }
        });
    }

    public function onGdriveScopes(Event $e): void
    {
        $e['declarations'] = [...$e['declarations'], [
            'plugin' => 'gdrive-backup',
            'account' => (string) $this->config->get('plugins.gdrive-backup.account', 'personal'),
            'scopes' => [$this->folder() === '' ? Drive::SCOPE_FILE : Drive::SCOPE_FULL],
        ]];
    }

    public function onSchedulerInitialized(Event $e): void
    {
        if (!$this->config->get('plugins.gdrive-backup.sync.enabled', true)) {
            return;
        }
        $job = $e['scheduler']->addFunction([$this, 'sync'], [], self::JOB);
        $job->at((string) $this->config->get('plugins.gdrive-backup.sync.at', '15 * * * *'));
        $job->output('logs/gdrive-backup.out');
        $job->backlink('/plugins/gdrive-backup');
    }

    /** Scheduled backups run in the CLI: upload now. "Backup now" in admin is a web request: leave it to the job. */
    public function onBackupFinished(Event $e): void
    {
        if (PHP_SAPI === 'cli') {
            $this->sync();
        }
    }

    /** One sync pass under the lock. Returns the one-line summary the scheduler logs. */
    public function sync(): string
    {
        $log = $this->grav['log'];
        if (!class_exists(Drive::class)) {
            if (!self::$warnedMissing) {
                self::$warnedMissing = true;
                $log->warning('gdrive-backup: the gdrive plugin is missing or disabled; nothing synced');
            }

            return 'gdrive-backup: gdrive plugin missing or disabled';
        }

        $summary = Sync::locked(Status::dir() . '/sync.lock', function (): string {
            $config = (array) $this->config->get('plugins.gdrive-backup', []);
            $previous = Status::read();
            $status = ['last_run' => date('c'), 'ok' => false, 'uploaded' => [], 'trashed' => [], 'errors' => [], 'folder_id' => (string) ($previous['folder_id'] ?? ''), 'drive_count' => 0];
            try {
                $site = Status::site();
                $folder = $this->folder();
                $drive = Gdrive::drive((string) ($config['account'] ?? 'personal'), [$folder === '' ? Drive::SCOPE_FILE : Drive::SCOPE_FULL]);
                $status['folder_id'] = Sync::folder($drive, $folder, $folder === '' ? $status['folder_id'] : '', $site);

                $local = [];
                foreach (Backups::getAvailableBackups(true) as $b) {
                    $local[(string) $b->filename] = (string) $b->path;
                }
                $result = (new Sync($drive, $status['folder_id'], $site, max(1, (int) ($config['keep'] ?? 10)), (array) ($config['generational'] ?? [])))->run($local);
                $status = [...$status, ...$result, 'ok' => $result['errors'] === []];
            } catch (\Throwable $e) {
                $status['errors'][] = $e->getMessage();
            }
            Status::write($status);
            foreach ($status['errors'] as $err) {
                $this->grav['log']->error('gdrive-backup: ' . $err);
            }

            return sprintf(
                'gdrive-backup: %s, uploaded %d, trashed %d, %d on Drive%s',
                $status['ok'] ? 'OK' : 'FAILED',
                count($status['uploaded']),
                count($status['trashed']),
                $status['drive_count'],
                $status['errors'] === [] ? '' : ': ' . implode('; ', $status['errors']),
            );
        });

        return $summary ?? 'gdrive-backup: another sync is running, skipped';
    }

    private function folder(): string
    {
        return Sync::folderId((string) $this->config->get('plugins.gdrive-backup.folder', ''));
    }
}
