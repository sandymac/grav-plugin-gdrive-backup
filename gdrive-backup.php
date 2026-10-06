<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Backup\Backups;
use Grav\Common\Data\Data;
use Grav\Common\Plugin;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Utils;
use Grav\Plugin\Gdrive\Drive;
use Grav\Plugin\Gdrive\Gdrive;
use Grav\Plugin\GdriveBackup\Status;
use Grav\Plugin\GdriveBackup\Sync;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\AbstractFile;

/**
 * Uploads Grav backups to Google Drive and applies retention there. Grav's own
 * backups.yaml purge still owns the LOCAL copies; this only manages Drive.
 */
class GdriveBackupPlugin extends Plugin
{
    public const VERSION = '1.0.0';
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
            'onAdminSave' => ['onAdminSave', 0],
        ];
    }

    public function autoload(): void
    {
        self::registerAutoload();
        // Admin2 may resolve data-*@ through the API's /data/resolve, which only calls allowlisted providers.
        // folderHelp gives away only the host name. Status::folderCheck must NOT be listed: /data/resolve
        // is open to anyone with api.pages.read, so a page editor could read the account's email and the
        // folder names and spend the site's Google token. The plugin's own blueprint resolves its
        // data-content@ server-side and doesn't need the allowlist (nor does Setup::consumerNotice).
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

    /**
     * Where backups go is api.gdrive.manage's call, not api.config.write's: a
     * config writer could point the site's Google credential at a folder they
     * control and receive every backup zip (Google keys and password hashes
     * included). Like gdrive-auth's `accounts`, a change by anyone else is put
     * back to what's on disk; the fields' help says so.
     */
    public function onAdminSave(Event $event): void
    {
        $obj = $event['object'] ?? null;
        $file = $obj instanceof Data ? $obj->file() : null;
        if (!$file instanceof AbstractFile || !str_ends_with(str_replace('\\', '/', (string) $file->filename()), '/plugins/gdrive-backup.yaml')) {
            return;
        }
        $saved = (array) $this->config->get('plugins.gdrive-backup', []);
        foreach (Sync::guardTarget($obj->toArray(), $saved, $this->canManageGdrive()) as $key => $value) {
            $obj->set($key, $value);
        }
    }

    /**
     * api.gdrive.manage (or a parent key, as the api plugin resolves it) or
     * api.super, for the admin saving the form: $grav['admin']->user under
     * Admin2 (its AdminProxy) and admin-classic alike. Not User::authorize():
     * the api plugin's JWT users aren't flagged authenticated, so it says no
     * to everyone. Same group-then-user lookup otherwise.
     * ponytail: any grant wins; a user-level deny over a group grant isn't honoured.
     */
    private function canManageGdrive(): bool
    {
        $user = isset($this->grav['admin']) ? ($this->grav['admin']->user ?? null) : null;
        if (!$user instanceof UserInterface) {
            return false;
        }
        $config = $this->grav['config'];
        $granted = static function (string $action) use ($user, $config): bool {
            $yes = Utils::isPositive($user->get("access.{$action}"));
            foreach ((array) $user->get('groups') as $group) {
                $yes = $yes || Utils::isPositive($config->get("groups.{$group}.access.{$action}"));
            }

            return $yes;
        };
        for ($key = 'api.gdrive.manage'; $key !== ''; $key = (string) substr($key, 0, (int) strrpos($key, '.'))) {
            if ($granted($key)) {
                return true;
            }
        }

        return $granted('api.super');
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

    /**
     * The scheduler job and the after-backup hook. Failure-safe: an unwritable
     * user/data or lock file must not break the backup that just ran, nor the
     * scheduler; the summary is what the job logs.
     */
    public function sync(): string
    {
        try {
            return $this->pass();
        } catch (\Throwable $e) {
            $this->grav['log']->error('gdrive-backup: ' . $e->getMessage());

            return 'gdrive-backup: FAILED: ' . $e->getMessage();
        }
    }

    /** One sync pass under the lock. Returns the one-line summary the scheduler logs. */
    private function pass(): string
    {
        $log = $this->grav['log'];
        if (!class_exists(Drive::class)) {
            if (!self::$warnedMissing) {
                self::$warnedMissing = true;
                $log->warning('gdrive-backup: the Google Drive Auth (gdrive-auth) plugin is missing or disabled; nothing synced');
            }

            return 'gdrive-backup: Google Drive Auth (gdrive-auth) plugin missing or disabled';
        }

        $summary = Sync::locked(Status::dir() . '/sync.lock', function (): string {
            $config = (array) $this->config->get('plugins.gdrive-backup', []);
            $folder = $this->folder();
            $previous = Status::read();
            $status = ['last_run' => date('c'), 'ok' => false, 'uploaded' => [], 'trashed' => [], 'errors' => [], 'warnings' => [], 'folder_id' => (string) ($previous['folder_id'] ?? ''), 'drive_count' => 0] + Sync::memory($previous, $folder);
            try {
                $site = Status::site();
                $account = (string) ($config['account'] ?? 'personal');
                $drive = Gdrive::drive($account, [$folder === '' ? Drive::SCOPE_FILE : Drive::SCOPE_FULL]);
                $sa = $this->config->get("plugins.gdrive-auth.accounts.{$account}.type") === 'service_account';
                $resolved = Sync::resolveFolder($drive, $folder, $status, $site, (bool) ($config['recreate_folder'] ?? true), $sa, $account);
                $status = ['folder_id' => $resolved['id']] + $resolved['status'];
                if ($resolved['warning'] !== null) {
                    $status['warnings'][] = $resolved['warning'];
                }

                $local = [];
                foreach (Backups::getAvailableBackups(true) as $b) {
                    $local[(string) $b->filename] = (string) $b->path;
                }
                $result = (new Sync($drive, $status['folder_id'], $site, max(1, (int) ($config['keep'] ?? 10)), (array) ($config['generational'] ?? [])))->run($local);
                $status = [...$status, ...$result, 'warnings' => [...$status['warnings'], ...$result['warnings']], 'ok' => $result['errors'] === []];
            } catch (\Throwable $e) {
                $status['errors'][] = $e->getMessage();
            }
            Status::write($status);
            foreach ($status['warnings'] as $w) {
                $this->grav['log']->warning('gdrive-backup: ' . $w);
            }
            foreach ($status['errors'] as $err) {
                $this->grav['log']->error('gdrive-backup: ' . $err);
            }

            return sprintf(
                'gdrive-backup: %s, uploaded %d, trashed %d, %d on Drive%s%s',
                $status['ok'] ? 'OK' : 'FAILED',
                count($status['uploaded']),
                count($status['trashed']),
                $status['drive_count'],
                $status['errors'] === [] ? '' : ': ' . implode('; ', $status['errors']),
                $status['warnings'] === [] ? '' : ' (warning: ' . implode('; ', $status['warnings']) . ')',
            );
        });

        return $summary ?? 'gdrive-backup: another sync is running, skipped';
    }

    private function folder(): string
    {
        return Sync::folderId((string) $this->config->get('plugins.gdrive-backup.folder', ''));
    }
}
