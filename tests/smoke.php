<?php

declare(strict_types=1);

/**
 * Smallest checks that fail if retention, filename parsing, the
 * upload-only-survivors rule, the starred/untagged/trash-only invariants, the
 * md5 check or the lock breaks. Drive is the real library client over a fake
 * in-memory transport; no network, no Grav install.
 * Run: php tests/smoke.php   (GDRIVE_LIB points at the gdrive library; default ../grav-plugin-gdrive)
 */

$lib = rtrim(getenv('GDRIVE_LIB') ?: __DIR__ . '/../../grav-plugin-gdrive', '/\\');
spl_autoload_register(static function (string $class) use ($lib): void {
    foreach (['Grav\\Plugin\\GdriveBackup\\' => __DIR__ . '/../classes/', 'Grav\\Plugin\\Gdrive\\' => $lib . '/classes/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix) && is_file($path = $dir . substr($class, strlen($prefix)) . '.php')) {
            require $path;
        }
    }
});

use Grav\Plugin\Gdrive\Credentials;
use Grav\Plugin\Gdrive\Drive;
use Grav\Plugin\Gdrive\DriveException;
use Grav\Plugin\GdriveBackup\Retention;
use Grav\Plugin\GdriveBackup\Status;
use Grav\Plugin\GdriveBackup\Sync;

function check(bool $ok, string $what): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
}

date_default_timezone_set('UTC');
$t = static fn (string $s): int => (int) strtotime($s . ' UTC');
$name = static fn (int $ts): string => 'default_site_backup--' . date('YmdHis', $ts) . '.zip';

// --- Filename → timestamp.
check(Retention::timestamp('default_site_backup--20260928120000.zip') === $t('2026-09-28 12:00:00'), 'parses <profile>--YmdHis.zip');
check(Retention::timestamp('my--profile--20260101000000.zip') === $t('2026-01-01 00:00:00'), 'profile names may contain --');
check(Retention::timestamp('x--2026092812000.zip') === null && Retention::timestamp('x--20260928120000.zip.part') === null && Retention::timestamp('notes.txt') === null, 'non-backup names do not parse');
check(Retention::timestamp('x--20261399000000.zip') === null, 'an impossible date does not parse');

// --- Retention: newest N.
$ts = [$t('2026-09-01'), $t('2026-09-03'), $t('2026-09-02'), $t('2026-09-04')];
check(Retention::keep($ts, 2, []) === [$t('2026-09-04'), $t('2026-09-03')], 'keep=2 keeps the two newest, newest first');
check(Retention::keep($ts, 0, []) === [] && Retention::keep([], 5, []) === [], 'keep=0 or no backups keeps nothing');
check(Retention::keep($ts, 10, []) === [$t('2026-09-04'), $t('2026-09-03'), $t('2026-09-02'), $t('2026-09-01')], 'keep > count keeps all');

// --- Generational: newest backup per bucket, the current period included, counted back from $now.
$now = $t('2026-09-28 18:00:00'); // a Monday
$daily = [$t('2026-09-28 01:00'), $t('2026-09-28 09:00'), $t('2026-09-27 23:59:59'), $t('2026-09-27 00:00'), $t('2026-09-25 12:00'), $t('2026-09-24 12:00')];
check(Retention::keep($daily, 0, ['days' => 3], $now) === [$t('2026-09-28 09:00'), $t('2026-09-27 23:59:59')], 'days=3: newest of today and yesterday, nothing on the empty third day, midnight splits days');
check(Retention::keep($daily, 1, ['days' => 5], $now) === [$t('2026-09-28 09:00'), $t('2026-09-27 23:59:59'), $t('2026-09-25 12:00'), $t('2026-09-24 12:00')], 'days=5 keeps one per day back to the 24th; keep=1 overlaps it');
// ISO weeks: 2026-09-28 is W40, the 27th (Sunday) is still W39.
$weekly = [$t('2026-09-28 00:30'), $t('2026-09-27 22:00'), $t('2026-09-21 08:00'), $t('2026-09-20 08:00'), $t('2026-09-14 08:00')];
check(Retention::keep($weekly, 0, ['weeks' => 2], $now) === [$t('2026-09-28 00:30'), $t('2026-09-27 22:00')], 'weeks=2: Sunday belongs to the previous ISO week');
check(Retention::keep($weekly, 0, ['weeks' => 3], $now) === [$t('2026-09-28 00:30'), $t('2026-09-27 22:00'), $t('2026-09-20 08:00')], 'weeks=3 keeps the newest of W38, not the older one');
$yearEnd = $t('2027-01-01 10:00'); // 2026-12-31 is a Thursday, so 2027-01-01 is still ISO week 2026-W53
check(Retention::keep([$t('2026-12-28 10:00'), $t('2026-12-27 10:00')], 0, ['weeks' => 1], $yearEnd) === [$t('2026-12-28 10:00')], 'ISO week straddles the new year');
$monthly = [$t('2026-09-01 00:00'), $t('2026-08-31 23:59'), $t('2026-08-01'), $t('2026-07-15'), $t('2026-06-30'), $t('2026-03-31')];
check(Retention::keep($monthly, 0, ['months' => 3], $now) === [$t('2026-09-01 00:00'), $t('2026-08-31 23:59'), $t('2026-07-15')], 'months=3: newest in Sep, Aug, Jul');
check(Retention::keep([$t('2026-02-28'), $t('2026-01-31')], 0, ['months' => 2], $t('2026-03-31 12:00')) === [$t('2026-02-28')], 'months counted from the 31st do not skip February');
$yearly = [$t('2026-01-01 00:00'), $t('2025-12-31 23:00'), $t('2025-06-01'), $t('2024-03-01'), $t('2023-03-01')];
check(Retention::keep($yearly, 0, ['years' => 3], $now) === [$t('2026-01-01 00:00'), $t('2025-12-31 23:00'), $t('2024-03-01')], 'years=3: newest of 2026, 2025, 2024');
check(Retention::keep([...$daily, ...$yearly], 1, ['days' => 1, 'years' => 2], $now) === [$t('2026-09-28 09:00'), $t('2025-12-31 23:00')], 'buckets combine and de-duplicate with keep');

// --- Sync against the real Drive client over a fake in-memory Drive.
$creds = new class () implements Credentials {
    public function token(array $scopes): string
    {
        return 'tok';
    }

    public function email(): string
    {
        return 'me@example.com';
    }

    public function forget(array $scopes): void
    {
    }
};

final class FakeDrive
{
    /** @var array<string, array> id => file */
    public array $files = [];
    public array $trashed = [];
    public array $queries = [];
    public bool $corrupt = false;
    private array $sessions = [];
    private int $next = 0;

    public function add(string $name, array $extra = []): string
    {
        $id = 'F' . ++$this->next;
        $this->files[$id] = $extra + ['id' => $id, 'name' => $name, 'md5Checksum' => md5($name), 'starred' => false, 'appProperties' => ['grav_backup' => '1', 'site' => 'example.com']];

        return $id;
    }

    public function names(): array
    {
        $n = array_column($this->files, 'name');
        sort($n);

        return $n;
    }

    public function __invoke(string $method, string $url, array $opts): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        if ($method === 'GET' && $path === '/drive/v3/files') {
            $this->queries[] = $q['q'];

            return [200, (string) json_encode(['files' => array_values($this->files)]), []]; // includes untagged ones on purpose
        }
        if ($method === 'POST' && str_starts_with($path, '/upload/')) {
            $sid = 'S' . count($this->sessions);
            $this->sessions[$sid] = json_decode($opts['body'], true);

            return [200, '', ['location' => "https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id={$sid}"]];
        }
        if ($method === 'PUT') {
            $meta = $this->sessions[$q['upload_id']];
            $bytes = stream_get_contents($opts['infile']);
            $id = $this->add($meta['name'], ['appProperties' => $meta['appProperties'], 'md5Checksum' => $this->corrupt ? md5('garbage') : md5((string) $bytes), 'parents' => $meta['parents']]);

            return [200, (string) json_encode($this->files[$id]), []];
        }
        if ($method === 'PATCH' && json_decode($opts['body'], true) === ['trashed' => true]) {
            $id = basename($path);
            $this->trashed[] = $this->files[$id]['name'];
            unset($this->files[$id]);

            return [200, (string) json_encode(['id' => $id]), []];
        }
        if ($method === 'GET' && preg_match('~/files/([^/]+)$~', $path, $m) === 1) {
            return $m[1] === 'GONE' ? [404, '{"error":{"errors":[{"reason":"notFound"}]}}', []] : [200, (string) json_encode(['id' => $m[1], 'trashed' => $m[1] === 'TRASHED']), []];
        }

        return [500, "unexpected {$method} {$path}", []];
    }
}

$tmp = sys_get_temp_dir() . '/gdrive-backup-smoke-' . getmypid();
@mkdir($tmp);
/** Writes local backups for the given timestamps; returns filename => path. */
$locals = static function (array $stamps) use ($tmp, $name): array {
    $out = [];
    foreach ($stamps as $ts) {
        file_put_contents($path = $tmp . '/' . $name($ts), 'zip ' . $ts);
        $out[$name($ts)] = $path;
    }

    return $out;
};
$sync = static fn (FakeDrive $fake, int $keep, array $gen = []): Sync => new Sync(new Drive($creds, [Drive::SCOPE_FILE], $fake), 'FOLDER', 'example.com', $keep, $gen, $now);
$d = [1 => $t('2026-09-21'), 2 => $t('2026-09-22'), 3 => $t('2026-09-23'), 4 => $t('2026-09-24')];

// First run: only the survivors are uploaded, tagged, into the folder.
$fake = new FakeDrive();
$r = $sync($fake, 2)->run($locals([$d[1], $d[2], $d[3]]));
check($r['uploaded'] === [$name($d[2]), $name($d[3])] && $r['trashed'] === [] && $r['errors'] === [] && $r['drive_count'] === 2, 'only local backups that survive retention are uploaded');
$up = array_values($fake->files)[0];
check($up['appProperties'] === ['grav_backup' => '1', 'site' => 'example.com'] && $up['parents'] === ['FOLDER'], 'uploads carry grav_backup=1 and site tags, into the folder');
check(str_contains($fake->queries[0], "'FOLDER' in parents") && str_contains($fake->queries[0], "appProperties has { key='grav_backup' and value='1' }"), 'Drive is listed by the grav_backup tag in the folder');

// A backup rotated off Drive but still on disk is not uploaded again.
$fake = new FakeDrive();
$fake->add($name($d[2]));
$fake->add($name($d[3]));
$r = $sync($fake, 2)->run($locals([$d[1], $d[2], $d[3]]));
check($r['uploaded'] === [] && $r['trashed'] === [] && $fake->names() === [$name($d[2]), $name($d[3])], 'a backup trashed from Drive but still local is not re-uploaded');

// A new backup: uploaded, the oldest Drive copy trashed; starred and untagged never touched.
$fake = new FakeDrive();
$fake->add($name($d[1]));
$starred = $fake->add($name($d[2]), ['starred' => true]);
$fake->add($name($d[3]));
$fake->add('manual--20200101000000.zip', ['appProperties' => []]);
$fake->add('notes.txt', ['appProperties' => ['other' => 'x']]);
$r = $sync($fake, 1)->run($locals([$d[4]]));
check($r['uploaded'] === [$name($d[4])] && $r['trashed'] === [$name($d[1]), $name($d[3])], 'new backup uploaded, unkept tagged copies trashed');
check(isset($fake->files[$starred]), 'a starred file is never trashed');
check(in_array('manual--20200101000000.zip', $fake->names(), true) && in_array('notes.txt', $fake->names(), true), 'untagged files are never considered, even if a listing returns them');
check($r['drive_count'] === 2, 'drive_count counts tagged, non-trashed copies after the run');

// Starred copies don't take a newest-N slot.
$fake = new FakeDrive();
$fake->add($name($d[4]), ['starred' => true]);
$fake->add($name($d[3]));
$r = $sync($fake, 1)->run([]);
check($r['trashed'] === [] && count($fake->files) === 2, 'a starred newest copy does not push the next one out of keep');

// md5 mismatch: the upload is trashed, an error recorded, and retention held back.
$fake = new FakeDrive();
$fake->corrupt = true;
$fake->add($name($d[1]));
$r = $sync($fake, 1)->run($locals([$d[4]]));
check($r['uploaded'] === [] && $fake->trashed === [$name($d[4])] && str_contains($r['errors'][0], 'md5 mismatch'), 'an md5 mismatch trashes the upload and records an error');
check($fake->names() === [$name($d[1])] && str_contains((string) end($r['errors']), 'retention skipped'), 'retention does not trash old copies while an upload failed');

// Generational retention reaches through to Drive.
$fake = new FakeDrive();
$fake->add($name($t('2026-07-10')));
$fake->add($name($t('2026-07-20')));
$fake->add($name($t('2026-08-05')));
$r = $sync($fake, 1, ['months' => 3])->run($locals([$d[4]]));
check($r['trashed'] === [$name($t('2026-07-10'))] && $fake->names() === [$name($t('2026-07-20')), $name($t('2026-08-05')), $name($d[4])], 'months=3 keeps the newest of July and August on Drive');

// --- Folder resolution: blank or set, alive, trashed or gone, recreate on or off.
final class FolderFake
{
    /** @var array<string, array> id => folder */
    public array $folders = [];
    /** @var list<array{string, string, ?array}> method, path, JSON body */
    public array $calls = [];
    public bool $refuseCreate = false;
    private int $next = 0;

    public function __invoke(string $method, string $url, array $opts): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $body = isset($opts['body']) ? json_decode($opts['body'], true) : null;
        $this->calls[] = [$method, $path, $body];
        if ($method === 'GET' && $path === '/drive/v3/files') { // ensureFolder's lookup: an untrashed same-name child
            preg_match("~^'([^']+)' in parents.* name='([^']+)'$~", $q['q'], $m);
            $hit = array_filter($this->folders, static fn (array $f): bool => empty($f['trashed']) && $f['name'] === $m[2] && $f['parents'] === [$m[1]]);

            return [200, (string) json_encode(['files' => array_values($hit)]), []];
        }
        if ($method === 'GET' && preg_match('~/files/([^/]+)$~', $path, $m) === 1) {
            return isset($this->folders[$m[1]]) ? [200, (string) json_encode($this->folders[$m[1]]), []] : [404, '{"error":{"errors":[{"reason":"notFound"}]}}', []];
        }
        if ($method === 'POST' && $path === '/drive/v3/files') {
            if ($this->refuseCreate) {
                return [403, '{"error":{"errors":[{"reason":"insufficientFilePermissions"}]}}', []];
            }
            $id = 'NEW' . ++$this->next;
            $this->folders[$id] = ['id' => $id, 'name' => $body['name'], 'trashed' => false, 'parents' => $body['parents']];

            return [200, (string) json_encode(['id' => $id]), []];
        }

        return [500, "unexpected {$method} {$path}", []];
    }

    /** @return list<array> the POSTs (folder creations) */
    public function created(): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'POST'));
    }
}
$ff = null;
$allCalls = [];
/** One resolveFolder() over a fresh fake; a stop comes back as ['stop' => message]. */
$resolve = static function (array $folders, string $configured, array $status, bool $recreate, bool $sa = false, bool $refuse = false) use ($creds, &$ff, &$allCalls): array {
    $ff = new FolderFake();
    $ff->folders = $folders;
    $ff->refuseCreate = $refuse;
    try {
        return Sync::resolveFolder(new Drive($creds, [Drive::SCOPE_FULL], $ff), $configured, $status, 'example.com', $recreate, $sa, 'personal');
    } catch (\RuntimeException $e) {
        return ['stop' => $e->getMessage()];
    } finally {
        $allCalls = [...$allCalls, ...$ff->calls];
    }
};
$dir = static fn (string $id, string $name, bool $trashed = false, array $parents = ['root']): array => [$id => ['id' => $id, 'name' => $name, 'trashed' => $trashed, 'parents' => $parents]];
$trashedWarning = 'Your configured folder is in the trash; backing up to the replacement. Update the Drive folder setting.';

// Blank setting.
$r = $resolve([], '', [], true);
check($r['id'] === 'NEW1' && $r['status']['auto_folder_id'] === 'NEW1' && $r['warning'] === null && $ff->created()[0][2]['name'] === 'Grav backups (example.com)' && $ff->created()[0][2]['parents'] === ['root'], 'blank, first run: creates "Grav backups (<site>)" in My Drive, no warning');
$r = $resolve([], '', [], false);
check($r['id'] === 'NEW1' && $r['warning'] === null, 'blank, first run creates even with recreate off');
$r = $resolve($dir('AUTO', 'Renamed by owner'), '', ['auto_folder_id' => 'AUTO'], true);
check($r['id'] === 'AUTO' && $r['warning'] === null && $ff->created() === [], 'blank: the remembered folder is reused (renamed or moved is fine)');
foreach (['trashed' => $dir('AUTO', 'Grav backups (example.com)', true), 'gone' => []] as $how => $folders) {
    $r = $resolve($folders, '', ['auto_folder_id' => 'AUTO'], true);
    check($r['id'] === 'NEW1' && $r['status']['auto_folder_id'] === 'NEW1' && $r['warning'] === 'The backup folder was in the trash or gone; created a new one.', "blank, {$how} + recreate: a new folder, remembered, with a warning");
    $r = $resolve($folders, '', ['auto_folder_id' => 'AUTO'], false);
    check(($r['stop'] ?? '') === "The plugin's backup folder is in the trash or gone. Restore it, or turn on Recreate a missing folder." && $ff->created() === [], "blank, {$how} + recreate off: the run stops, nothing created");
}

// Set folder.
$r = $resolve($dir('CFG', 'Backups'), 'CFG', ['auto_folder_id' => '', 'replacement' => ['for' => 'CFG', 'id' => 'OLDREP']], true);
check($r['id'] === 'CFG' && $r['warning'] === null && $r['status']['replacement'] === null && $ff->created() === [], 'set, OK: the configured folder is used and a stale replacement dropped');
$r = $resolve($dir('CFG', 'Backups', true, ['0ASHARED']), 'CFG', ['auto_folder_id' => ''], true);
check($r['id'] === 'NEW1' && $r['status']['replacement'] === ['for' => 'CFG', 'id' => 'NEW1'] && $ff->created()[0][2]['name'] === 'Backups' && $ff->created()[0][2]['parents'] === ['0ASHARED'], 'set, trashed + recreate: a same-name folder in its first parent (a Shared Drive root here), remembered');
check($r['warning'] === $trashedWarning, 'set, trashed: warns to update the setting');
$live = $dir('CFG', 'Backups', true) + $dir('REP', 'Backups');
$r = $resolve($live, 'CFG', ['auto_folder_id' => '', 'replacement' => ['for' => 'CFG', 'id' => 'REP']], true);
check($r['id'] === 'REP' && $ff->created() === [] && $r['warning'] === $trashedWarning, 'set, trashed + a live replacement: reused without creating, still warns');
$r = $resolve($live, 'CFG', ['auto_folder_id' => '', 'replacement' => ['for' => 'CFG', 'id' => 'REP']], false);
check(($r['id'] ?? '') === 'REP', 'set, trashed + a live replacement: reused even with recreate off');
$r = $resolve($dir('CFG', 'Backups', true) + $dir('REP', 'Backups', true), 'CFG', ['auto_folder_id' => '', 'replacement' => ['for' => 'CFG', 'id' => 'REP']], true);
check($r['id'] === 'NEW1' && $r['status']['replacement']['id'] === 'NEW1', 'set, trashed + a trashed replacement: a fresh one');
$r = $resolve($dir('CFG', 'Backups', true) + $dir('REP', 'Backups', false, ['ELSEWHERE']), 'CFG', ['auto_folder_id' => '', 'replacement' => ['for' => 'OTHER', 'id' => 'REP']], true);
check($r['id'] === 'NEW1', "set, trashed: another folder's replacement is not borrowed");
$r = $resolve($dir('CFG', 'Backups', true), 'CFG', ['auto_folder_id' => ''], false);
check(($r['stop'] ?? '') === 'Your configured Drive folder is in the trash. Restore it or choose another, or turn on Recreate a missing folder.' && $ff->created() === [], 'set, trashed + recreate off: the run stops');
$r = $resolve($dir('CFG', 'Backups', true, []), 'CFG', ['auto_folder_id' => ''], true);
check($r['id'] === 'NEW1' && $ff->created()[0][2]['parents'] === ['root'] && str_contains((string) $r['warning'], "backing up to 'Grav backups (example.com)' in My Drive"), 'set, trashed with no parent: falls back to My Drive');
$r = $resolve($dir('CFG', 'Backups', true), 'CFG', ['auto_folder_id' => ''], true, true, true);
check(str_contains($r['stop'] ?? '', "couldn't be found") && str_contains($r['stop'], 'Service accounts have no My Drive'), "set, trashed, can't create beside it, service account: stops as if gone");

$r = $resolve([], 'CFG', ['auto_folder_id' => ''], true);
check($r['id'] === 'NEW1' && $r['status']['replacement'] === ['for' => 'CFG', 'id' => 'NEW1'] && $ff->created()[0][2]['name'] === 'Grav backups (example.com)' && $ff->created()[0][2]['parents'] === ['root'], 'set, 404 + OAuth + recreate: "Grav backups (<site>)" in My Drive, remembered');
check($r['warning'] === "Your configured Drive folder couldn't be found (deleted, or no longer shared); backing up to 'Grav backups (example.com)' in My Drive. Update the Drive folder setting.", 'set, 404: warns with the fallback folder name');
$r = $resolve($dir('REP', 'Grav backups (example.com)'), 'CFG', ['auto_folder_id' => '', 'replacement' => ['for' => 'CFG', 'id' => 'REP']], false);
check(($r['id'] ?? '') === 'REP' && $ff->created() === [] && str_contains((string) $r['warning'], "couldn't be found"), 'set, 404 + a live replacement: reused');
$r = $resolve([], 'CFG', ['auto_folder_id' => ''], true, true);
check(($r['stop'] ?? '') === "Your configured Drive folder couldn't be found. It was deleted or is no longer shared with personal. Service accounts have no My Drive to fall back to." && $ff->created() === [], 'set, 404 + service account: stops, nothing created');
$r = $resolve([], 'CFG', ['auto_folder_id' => ''], false);
check(($r['stop'] ?? '') === "Your configured Drive folder couldn't be found. It was deleted or is no longer shared with personal." && $ff->created() === [], 'set, 404 + recreate off: stops');
try {
    Sync::resolveFolder(new Drive($creds, [Drive::SCOPE_FULL], static fn (): array => [500, '{"error":{"errors":[{"reason":"backendError"}]}}', []]), 'CFG', [], 'example.com', true);
    $err = null;
} catch (DriveException $e) {
    $err = $e->reason;
}
check($err === 'backendError', 'any other Drive error is rethrown, so the run stops');

// Bug B: a configured folder is not reused once the setting is cleared.
$st = $resolve($dir('CFG', 'Backups'), 'CFG', [], true)['status'];
$st['folder_id'] = 'CFG'; // what sync() writes for display
$r = $resolve($dir('CFG', 'Backups'), '', $st, true);
check($r['id'] === 'NEW1' && $r['status']['auto_folder_id'] === 'NEW1', 'set → cleared to blank: the configured folder is not reused, the plugin makes its own');

// Old status (0.1.4 and earlier) had only folder_id.
$r = $resolve($dir('OLD', 'Grav backups (example.com)'), '', ['folder_id' => 'OLD'], true);
check($r['id'] === 'OLD' && $r['status']['auto_folder_id'] === 'OLD' && $ff->created() === [], 'migration, blank: the old folder_id is the auto folder');
$r = $resolve($dir('OLD', 'Grav backups (example.com)') + $dir('CFG', 'Backups'), 'CFG', ['folder_id' => 'OLD'], true);
check($r['id'] === 'CFG' && $r['status']['auto_folder_id'] === '', 'migration, set: the old folder_id is ignored');
check(Sync::memory(['folder_id' => 'X', 'auto_folder_id' => ''], '') === ['auto_folder_id' => '', 'replacement' => null], 'migration runs once: a saved empty auto_folder_id stays empty');

check(count($allCalls) > 30 && array_filter($allCalls, static fn (array $c): bool => $c[0] === 'DELETE' || $c[0] === 'PATCH' || ($c[2]['trashed'] ?? null) === false) === [], 'folder resolution never deletes, patches or untrashes anything');

// --- Folder setting: a bare id or any Drive folder link the owner pastes.
$id = '1LwqGziKeXXdo1T5y4q3dqEuAehSGCuAT';
foreach ([
    $id, "  {$id}  ",
    "https://drive.google.com/drive/folders/{$id}",
    "https://drive.google.com/drive/folders/{$id}?usp=sharing",
    "https://drive.google.com/drive/u/1/folders/{$id}?usp=drive_link",
    "https://drive.google.com/open?id={$id}",
    "https://drive.google.com/folderview?usp=sharing&id={$id}",
] as $pasted) {
    check(Sync::folderId($pasted) === $id, "folderId() extracts the id from: {$pasted}");
}
check(Sync::folderId('') === '' && Sync::folderId('   ') === '', 'folderId() keeps empty as empty (plugin makes its own folder)');
check(Sync::folderId('https://drive.google.com/drive/folders/0AK0-ofF-eHHHUk9PVA') === '0AK0-ofF-eHHHUk9PVA', 'folderId() handles a Shared Drive root link');
check(Sync::folderName('example.com') === 'Grav backups (example.com)', 'folderName() is the one place the folder name is built');
$help = \Grav\Plugin\GdriveBackup\Status::folderHelp(); // no Grav here: must fall back, not throw
check(str_contains($help, 'Grav backups') && !str_contains($help, '<') && !str_contains($help, '()'), 'folderHelp() falls back to words without Grav, with no tag-like text Admin2 would strip');

// --- Which backup profiles are scheduled: a scheduler toggle wins, else the profile's schedule flag.
$hyph = static fn (string $n): string => strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $n), '-'));
$profiles = [
    ['name' => 'Default Site Backup', 'schedule' => true, 'schedule_at' => '0 3 * * *'],
    ['name' => 'Pages Only', 'schedule' => false, 'schedule_at' => '0 4 * * 0'],
    ['name' => 'Media', 'schedule' => true, 'schedule_at' => '0 5 1 * *'],
];
check(Status::activeProfiles($profiles, [], $hyph) === [['Default Site Backup', '0 3 * * *'], ['Media', '0 5 1 * *']], 'activeProfiles(): the schedule flag decides when no toggle is set');
check(Status::activeProfiles($profiles, ['pages-only' => 'enabled', 'media' => 'disabled'], $hyph) === [['Default Site Backup', '0 3 * * *'], ['Pages Only', '0 4 * * 0']], 'activeProfiles(): an Enabled/Disabled toggle overrides the flag');
check(Status::activeProfiles([], [], $hyph) === [], 'activeProfiles(): no profiles, none active');
check(Status::profileList([['Default Site Backup', '0 3 * * *'], ['My *site*', '']]) === 'Default Site Backup (`0 3 * * *`), My site', 'profileList() keeps a cron schedule\'s * intact in its code span, and strips markup from names');
check(!str_contains(Status::profileList([['x', "0 3 `* * *"]]), '``'), 'profileList(): a backtick in a schedule cannot break out of its code span');
$notice = Status::profilesNotice(); // no Grav here: must fall back, not throw
check(str_contains($notice, 'Configuration → Backups') && !str_contains($notice, '<'), 'profilesNotice() falls back to plain words without Grav');

// --- Lock: a held lock skips the run.
$lock = $tmp . '/sync.lock';
check(Sync::locked($lock, static fn (): string => 'ran') === 'ran', 'an unheld lock runs the job');
$held = fopen($lock, 'c');
flock($held, LOCK_EX);
check(Sync::locked($lock, static fn (): string => 'ran') === null, 'a held lock skips the job');
flock($held, LOCK_UN);
fclose($held);
check(Sync::locked($lock, static fn (): string => 'ran') === 'ran', 'the lock is usable again once released');

foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

// --- Version drift: GPM installs blueprints.yaml's version; the plugin reports its constant.
$blueprints = (string) file_get_contents(__DIR__ . '/../blueprints.yaml');
preg_match('/^version:\s*(\S+)/m', $blueprints, $bv);
preg_match("/const VERSION = '([^']+)'/", (string) file_get_contents(__DIR__ . '/../gdrive-backup.php'), $pv);
check(($bv[1] ?? '') === ($pv[1] ?? 'missing'), sprintf('GdriveBackupPlugin::VERSION (%s) matches blueprints.yaml (%s)', $pv[1] ?? 'missing', $bv[1] ?? 'missing'));

echo "smoke: OK\n";
