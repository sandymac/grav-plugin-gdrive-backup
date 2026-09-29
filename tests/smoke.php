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
    /** '' normal; 'upload': the upload response has no md5Checksum; 'always': files.get has none either */
    public string $noMd5 = '';
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

            return [200, (string) json_encode(array_diff_key($this->files[$id], $this->noMd5 !== '' ? ['md5Checksum' => 1] : [])), []];
        }
        if ($method === 'PATCH' && json_decode($opts['body'], true) === ['trashed' => true]) {
            $id = basename($path);
            $this->trashed[] = $this->files[$id]['name'];
            unset($this->files[$id]);

            return [200, (string) json_encode(['id' => $id]), []];
        }
        if ($method === 'GET' && preg_match('~/files/([^/]+)$~', $path, $m) === 1) {
            $this->queries[] = "get {$m[1]} {$q['fields']}";

            return isset($this->files[$m[1]]) ? [200, (string) json_encode(array_diff_key($this->files[$m[1]], $this->noMd5 === 'always' ? ['md5Checksum' => 1] : [])), []] : [404, '{"error":{"errors":[{"reason":"notFound"}]}}', []];
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
        file_put_contents($path = $tmp . '/' . $name($ts), $name($ts)); // so FakeDrive::add()'s md5($name) is a good copy
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

// No md5Checksum in the upload response: fetched once, then verified as usual.
$fake = new FakeDrive();
$fake->noMd5 = 'upload';
$fake->add($name($d[1]));
$r = $sync($fake, 1)->run($locals([$d[4]]));
check($r['uploaded'] === [$name($d[4])] && $r['warnings'] === [] && $r['trashed'] === [$name($d[1])] && preg_grep('/^get F\d+ md5Checksum$/', $fake->queries) !== [], 'no checksum in the upload response: files.get supplies it, verified, retention runs');
$fake = new FakeDrive();
$fake->noMd5 = 'upload';
$fake->corrupt = true;
$r = $sync($fake, 1)->run($locals([$d[4]]));
check($r['uploaded'] === [] && $fake->trashed === [$name($d[4])] && str_contains($r['errors'][0], 'md5 mismatch'), 'a fetched checksum that differs still trashes the upload');
$fake = new FakeDrive();
$fake->noMd5 = 'always';
$fake->add($name($d[1]));
$r = $sync($fake, 1)->run($locals([$d[4]]));
check($r['uploaded'] === [$name($d[4])] && in_array($name($d[4]), $fake->names(), true) && $r['errors'] === [] && $r['warnings'] === ["Drive didn't report a checksum for {$name($d[4])}, so it couldn't be verified."], 'no checksum at all: the upload is kept, counted, and warned about');

// A same-name copy on Drive with a different md5 is replaced, and trashed only once the new one is verified.
$fake = new FakeDrive();
$fake->add($name($d[1]));
$badId = $fake->add($name($d[4]), ['md5Checksum' => md5('truncated')]);
$r = $sync($fake, 1)->run($locals([$d[4]]));
$copies = array_values(array_filter($fake->files, static fn (array $f): bool => $f['name'] === $name($d[4])));
check($r['uploaded'] === [$name($d[4])] && !isset($fake->files[$badId]) && count($copies) === 1 && $copies[0]['md5Checksum'] === md5($name($d[4])), 'a mismatched Drive copy is uploaded again and the bad copy trashed by id');
check($r['trashed'] === [$name($d[4]), $name($d[1])] && $r['errors'] === [] && $r['drive_count'] === 1 && str_contains($r['warnings'][0] ?? '', "didn't match"), 'after the replacement, retention runs and the count is right');
$fake = new FakeDrive();
$fake->corrupt = true;
$fake->add($name($d[1]));
$badId = $fake->add($name($d[4]), ['md5Checksum' => md5('truncated')]);
$r = $sync($fake, 1)->run($locals([$d[4]]));
check(isset($fake->files[$badId]) && in_array($name($d[1]), $fake->names(), true) && $fake->trashed === [$name($d[4])] && str_contains((string) end($r['errors']), 'retention skipped'), 'a failed re-upload keeps the mismatched copy and skips retention');
$fake = new FakeDrive();
$fake->add($name($d[4]), ['md5Checksum' => null]);
$r = $sync($fake, 1)->run($locals([$d[4]]));
check($r['uploaded'] === [] && count($fake->files) === 1, 'a Drive copy with no checksum is left alone, not re-uploaded every run');

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
    public bool $failList = false;
    private int $next = 0;

    public function __invoke(string $method, string $url, array $opts): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $body = isset($opts['body']) ? json_decode($opts['body'], true) : null;
        $this->calls[] = [$method, $path, $body];
        if ($method === 'GET' && $path === '/drive/v3/files') { // untrashed children: folders (by name, if asked), or tagged backups
            if ($this->failList) {
                return [500, '{"error":{"errors":[{"reason":"backendError"}]}}', []];
            }
            preg_match("~^'([^']+)' in parents~", $q['q'], $p);
            $name = preg_match("~name='([^']+)'~", $q['q'], $n) === 1 ? $n[1] : null;
            $tagged = str_contains($q['q'], 'appProperties has');
            $hit = array_filter($this->folders, static fn (array $f): bool => empty($f['trashed']) && in_array($p[1], $f['parents'], true)
                && ($tagged ? ($f['appProperties']['grav_backup'] ?? '') === '1' : !isset($f['appProperties']) && ($name === null || $f['name'] === $name)));

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
$r = $resolve(['CFG' => ['driveId' => '0ASD'] + $dir('CFG', 'Backups', true)['CFG']], 'CFG', ['auto_folder_id' => ''], true, true, true);
check(str_contains($r['stop'] ?? '', "couldn't be found") && str_contains($r['stop'], 'Service accounts have no My Drive'), "set, trashed, can't create beside it, service account: stops as if gone");

// Recreating beside a trashed folder never adopts a live same-name folder: "(2)", then "(3)".
$r = $resolve($dir('CFG', 'Backups', true, ['P']) + $dir('OTHER', 'Backups', false, ['P']), 'CFG', ['auto_folder_id' => ''], true);
check($r['id'] === 'NEW1' && $ff->created()[0][2] === ['name' => 'Backups (2)', 'mimeType' => Drive::FOLDER, 'parents' => ['P']], 'set, trashed + a live same-name sibling: a new "Backups (2)", the sibling not adopted');
$r = $resolve($dir('CFG', 'Backups', true, ['P']) + $dir('OTHER', 'Backups', false, ['P']) + $dir('OTHER2', 'Backups (2)', false, ['P']) + $dir('ELSE', 'Backups (3)', false, ['Q']), 'CFG', ['auto_folder_id' => ''], true);
check($r['id'] === 'NEW1' && $ff->created()[0][2]['name'] === 'Backups (3)', 'set, trashed + "Backups" and "Backups (2)" taken: "Backups (3)" (names elsewhere do not count)');

// Service account + a configured folder in My Drive: stop before uploading, trashed or not.
$saMy = 'This folder is in My Drive. A service account has no storage there, so Google refuses uploads. Use a folder in a Shared Drive, shared with the service account as Content manager, or use an OAuth account.';
foreach ([false, true] as $trashed) {
    $r = $resolve($dir('CFG', 'Backups', $trashed), 'CFG', ['auto_folder_id' => ''], true, true);
    check(($r['stop'] ?? '') === $saMy && $ff->created() === [], 'set, service account, My Drive folder' . ($trashed ? ' (trashed)' : '') . ': the run stops');
}
$r = $resolve(['CFG' => ['driveId' => '0ASD'] + $dir('CFG', 'Backups')['CFG']], 'CFG', ['auto_folder_id' => ''], true, true);
check(($r['id'] ?? '') === 'CFG', 'set, service account, Shared Drive folder: used');

// The configured folder is back after a replacement: use it, but point at the replacement while it holds backups.
$backup = ['B1' => ['id' => 'B1', 'name' => 'x--20260101000000.zip', 'trashed' => false, 'parents' => ['REP'], 'appProperties' => ['grav_backup' => '1']]];
$back = 'Your folder is back. Backups made while it was missing are still in "Backups" ([open it](https://drive.google.com/drive/folders/REP)). Move them into your folder, or trash that folder once you don\'t need them.';
$r = $resolve($dir('CFG', 'Backups') + $dir('REP', 'Backups') + $backup, 'CFG', ['auto_folder_id' => '', 'replacement' => ['for' => 'CFG', 'id' => 'REP']], true);
check($r['id'] === 'CFG' && $r['status']['replacement'] === ['for' => 'CFG', 'id' => 'REP'] && $r['warning'] === $back, 'set, back + replacement holds backups: the configured folder, the replacement remembered, a warning with its link');
foreach (['empty' => $dir('REP', 'Backups'), 'trashed' => $dir('REP', 'Backups', true) + $backup, 'gone' => []] as $how => $reps) {
    $r = $resolve($dir('CFG', 'Backups') + $reps, 'CFG', ['auto_folder_id' => '', 'replacement' => ['for' => 'CFG', 'id' => 'REP']], true);
    check($r['id'] === 'CFG' && $r['status']['replacement'] === null && $r['warning'] === null, "set, back + replacement {$how}: forgotten, no warning");
}
$fail = new FolderFake();
$fail->folders = $dir('CFG', 'Backups') + $dir('REP', 'Backups') + $backup;
$fail->failList = true;
$r = Sync::resolveFolder(new Drive($creds, [Drive::SCOPE_FULL], $fail), 'CFG', ['replacement' => ['for' => 'CFG', 'id' => 'REP']], 'example.com', true);
check($r['id'] === 'CFG' && $r['status']['replacement'] === ['for' => 'CFG', 'id' => 'REP'] && $r['warning'] === null, "set, back + the replacement can't be listed: the backup goes ahead, the replacement still remembered");

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

// --- Folder check: the settings page's line, branch for branch with resolveFolder().
$auth = 'https://example.com/admin/plugins/gdrive';
$verdict = static fn (string $configured = 'CFG', array $status = [], ?array $file = null, ?array $rep = null, string $driveName = '', bool $recreate = true, bool $sa = false, ?\Throwable $error = null): array
    => Sync::folderVerdict($configured, Sync::memory($status, $configured), $file, $rep, $driveName, $recreate, $sa, 'personal', $sa ? 'svc@p.iam.gserviceaccount.com' : 'me@example.com', 'example.com', $error, $auth);
$f = static fn (string $name, array $extra = []): array => $extra + ['id' => 'CFG', 'name' => $name, 'trashed' => false, 'parents' => ['root'], 'capabilities' => ['canAddChildren' => true, 'canTrashChildren' => true]];
$shared = ['driveId' => '0ASD', 'parents' => ['0ASD']];
$withRep = ['replacement' => ['for' => 'CFG', 'id' => 'REP']];
$cases = [
    'blank, first sync' => [$verdict(''), ['✔', 'Will create "Grav backups (example.com)" in `me@example.com`\'s My Drive on the first sync.']],
    'blank, service account' => [$verdict('', [], null, null, '', true, true), ['✘', 'Service accounts have no My Drive: set a folder in a Shared Drive shared with `svc@p.iam.gserviceaccount.com` as **Content manager**.']],
    'blank, service account wins over an auto folder' => [$verdict('', ['auto_folder_id' => 'AUTO'], $f('x'), null, '', true, true)[0], '✘'],
    'blank, own folder alive' => [$verdict('', ['auto_folder_id' => 'AUTO'], $f('Grav backups (example.com)')), ['✔', 'Backing up to "Grav backups (example.com)" in My Drive.']],
    'blank, own folder trashed + recreate' => [$verdict('', ['auto_folder_id' => 'AUTO'], $f('x', ['trashed' => true])), ['⚠', 'The backup folder is in the trash or gone; the next sync creates a new one.']],
    'blank, own folder gone + recreate off' => [$verdict('', ['auto_folder_id' => 'AUTO'], null, null, '', false), ['✘', 'The backup folder is in the trash or gone; the next sync will stop. Restore it or turn on **Recreate a missing folder**.']],
    'set, My Drive, OK' => [$verdict('CFG', [], $f('Backups')), ['✔', '"Backups" in My Drive: can add and remove backups.']],
    'set, Shared Drive, OK' => [$verdict('CFG', [], $f('Backups', $shared), null, 'Team'), ['✔', '"Backups" in Shared Drive "Team": can add and remove backups.']],
    'set, Shared Drive, name lookup failed' => [$verdict('CFG', [], $f('Backups', $shared))[1], '"Backups" in a Shared Drive: can add and remove backups.'],
    'set, Shared Drive, Contributor' => [$verdict('CFG', [], $f('Backups', $shared + ['capabilities' => ['canAddChildren' => true, 'canTrashChildren' => false]])), ['⚠', "Uploads will work, but old copies can't be moved to the trash (retention). Share it as **Content manager**."]],
    'set, My Drive, canTrashChildren false is fine' => [$verdict('CFG', [], $f('Backups', ['capabilities' => ['canAddChildren' => true, 'canTrashChildren' => false]]))[0], '✔'],
    'set, view only' => [$verdict('CFG', [], $f('Backups', $shared + ['capabilities' => ['canAddChildren' => false, 'canTrashChildren' => false]])), ['✘', 'This account can only view "Backups". Share it as **Editor** (My Drive) or **Content manager** (Shared Drive).']],
    'set, trashed + live replacement' => [$verdict('CFG', $withRep, $f('Backups', ['trashed' => true]), $f('Backups', ['id' => 'REP']), '', false), ['⚠', 'Your folder is in the trash; backups go to the replacement "Backups". Update the Drive folder setting.']],
    'set, trashed + another folder\'s replacement' => [$verdict('CFG', ['replacement' => ['for' => 'OTHER', 'id' => 'REP']], $f('Backups', ['trashed' => true]), $f('Backups', ['id' => 'REP']), '', false)[0], '✘'],
    'set, trashed + trashed replacement + recreate' => [$verdict('CFG', $withRep, $f('Backups', ['trashed' => true]), $f('Backups', ['id' => 'REP', 'trashed' => true])), ['⚠', 'Your folder is in the trash. The next sync creates a new folder beside it with the same name (or with " (2)" if that name is taken).']],
    'set, back + replacement holds backups' => [$verdict('CFG', $withRep, $f('Backups'), $f('Backups', ['id' => 'REP'])), ['⚠', $back]],
    'set, back + replacement emptied (leftover() null)' => [$verdict('CFG', $withRep, $f('Backups'))[0], '✔'],
    'set, back + another folder\'s replacement' => [$verdict('CFG', ['replacement' => ['for' => 'OTHER', 'id' => 'REP']], $f('Backups'), $f('Backups', ['id' => 'REP']))[0], '✔'],
    'set, service account, My Drive' => [$verdict('CFG', [], $f('Backups'), null, '', true, true), ['✘', $saMy]],
    'set, service account, My Drive, view-only too' => [$verdict('CFG', [], $f('Backups', ['capabilities' => ['canAddChildren' => false]]), null, '', true, true), ['✘', $saMy]],
    'set, service account, My Drive, trashed' => [$verdict('CFG', [], $f('Backups', ['trashed' => true]), null, '', true, true), ['✘', $saMy]],
    'set, service account, Shared Drive' => [$verdict('CFG', [], $f('Backups', $shared), null, 'Team', true, true)[0], '✔'],
    'set, trashed + recreate off' => [$verdict('CFG', [], $f('Backups', ['trashed' => true]), null, '', false), ['✘', 'Your folder is in the trash; the next sync will stop. Restore it or choose another, or turn on **Recreate a missing folder**.']],
    'set, trashed, no parent, OAuth' => [$verdict('CFG', [], $f('Backups', ['trashed' => true, 'parents' => []])), ['⚠', 'Your folder is in the trash; the next sync backs up to "Grav backups (example.com)" in My Drive instead.']],
    'set, trashed, no parent, service account' => [$verdict('CFG', [], $f('Backups', ['trashed' => true, 'parents' => [], 'driveId' => '0ASD']), null, '', true, true), ['✘', 'Your folder is in the trash; the next sync will stop.']],
    'set, 404 + OAuth + recreate' => [$verdict('CFG'), ['✘', "`me@example.com` can't see that folder: check the link, and share it with that account; the next sync backs up to \"Grav backups (example.com)\" in My Drive instead."]],
    'set, 404 + recreate off' => [$verdict('CFG', [], null, null, '', false)[1], "`me@example.com` can't see that folder: check the link, and share it with that account; the next sync will stop."],
    'set, 404 + service account' => [$verdict('CFG', [], null, null, '', true, true)[1], "`svc@p.iam.gserviceaccount.com` can't see that folder: check the link, and share it with that account; the next sync will stop."],
    'set, 404 + live replacement' => [$verdict('CFG', $withRep, null, $f('Grav backups (example.com)', ['id' => 'REP'])), ['⚠', "`me@example.com` can't see that folder; backups go to the replacement \"Grav backups (example.com)\". Update the Drive folder setting."]],
    'scope, set' => [$verdict('CFG', [], null, null, '', true, false, new DriveException('x', 'scope_not_granted')), ['✘', "Needs `drive` access to use a folder you picked: click **Reconnect** on [Google Drive Auth]({$auth})."]],
    'scope, blank' => [$verdict('', [], null, null, '', true, false, new DriveException('x', 'scope_not_granted'))[1], "Needs `drive.file` access: click **Reconnect** on [Google Drive Auth]({$auth})."],
    'not connected' => [$verdict('CFG', [], null, null, '', true, false, new DriveException('x', 'not_connected')), ['✘', "The account isn't connected yet: click **Connect** on [Google Drive Auth]({$auth})."]],
    'unknown account' => [$verdict('CFG', [], null, null, '', true, false, new DriveException('x', 'unknown_account')), ['✘', "No account named **personal** yet: add it on [Google Drive Auth]({$auth})."]],
    'other Drive error' => [$verdict('CFG', [], null, null, '', true, false, DriveException::fromResponse(403, '{"error":{"errors":[{"reason":"accessNotConfigured"}],"message":"Drive API is off"}}', 'GET /files/CFG')), ['✘', "Couldn't check the folder: GET /files/CFG failed (HTTP 403) accessNotConfigured: Drive API is off ([how to fix]({$auth}#troubleshooting--access-not-configured))"]],
    'transport' => [$verdict('CFG', [], null, null, '', true, false, new DriveException('timed out', 'transport')), ['•', "Couldn't reach Google to check the folder right now."]],
];
foreach ($cases as $what => [$got, $want]) {
    check($got === $want, "folderVerdict(), {$what}: got " . json_encode($got, JSON_UNESCAPED_UNICODE));
}
$evil = $verdict('CFG', [], $f('<script>x</script> `a` [l](j:x) *b* _c_ |d| \\e'), null, '<div id="z">')[1];
check(!str_contains($evil, '<') && !str_contains($evil, '`') && !str_contains($evil, '[') && !str_contains($evil, '*') && !str_contains($evil, '_') && !str_contains($evil, '|') && !str_contains($evil, '\\'), 'folderVerdict() strips markup from Drive names: ' . $evil);
$evil = Sync::folderVerdict('', ['auto_folder_id' => '', 'replacement' => null], null, null, '', true, false, 'p', "a`b\n<script>@x", 'ex_<a>', null, $auth)[1];
check(substr_count($evil, '`') === 2 && !str_contains($evil, '<'), 'folderVerdict(): an email cannot break out of its code span, the site cannot inject tags: ' . $evil);
$evil = $verdict('CFG', $withRep, $f('Backups'), $f('<script>[x](j:y)</script>', ['id' => 'R")<div id="z">']))[1];
check(!str_contains($evil, '<') && !str_contains($evil, '[x]') && str_contains($evil, 'folders/Rdividz)'), 'folderVerdict(): the replacement\'s name and id cannot inject markup: ' . $evil);
$long = $verdict('CFG', [], null, null, '', true, false, new \RuntimeException(str_repeat('é', 400)))[1];
check(mb_strlen($long) < 220 && !str_contains($long, 'how to fix'), 'folderVerdict(): a long non-Drive error is shortened, with no fix link');
check(Status::folderCheck() === "• Couldn't check the folder right now.", 'folderCheck() without Grav: a neutral line, not an exception');

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

// --- Page editors can't reach the folder check through Admin2's /data/resolve.
$main = (string) file_get_contents(__DIR__ . '/../gdrive-backup.php');
check(preg_match('/addAllowedDynamicCallable\([^;]*folderCheck/', $main) === 0 && preg_match('/addAllowedDynamicCallable\([^;]*folderHelp/', $main) === 1, 'folderCheck is not on the dynamic-callable allowlist; folderHelp is');

// --- Does a backup profile's zip hold user/data/gdrive? Grav's matching: relative to the root, prefix, slashes trimmed.
$grav = "/backup\r\n/cache\r\n/images\r\n/logs\r\n/tmp";
foreach ([
    [true, '/', $grav, "Grav's default profile"],
    [true, '/', '', 'no excludes'],
    [false, '/', "{$grav}\r\n/user/data/gdrive", 'exact path, CRLF'],
    [false, '/', "/tmp\nuser/data/gdrive/", 'no leading slash, trailing slash, LF'],
    [false, '/', '/user/data', 'a parent: /user/data'],
    [false, '/', "/cache\r\n/user\r\n", 'a parent: /user'],
    [false, '/', '/cache, /user/data/gdrive', 'comma-separated'],
    [true, '/', '/user/dat', 'a partial name is not a parent'],
    [true, '/', '/user/data/gdrive-backup', 'a sibling with a longer name does not cover it'],
    [false, '/user/pages', '', 'a root elsewhere does not include it'],
    [true, '/user', '/tmp', 'root /user includes it'],
    [false, '/user', '/data/gdrive', 'root /user: excludes are relative to it'],
    [true, 'user://', '/user/data/gdrive', 'root user://: /user/data/gdrive would be user/user/…'],
] as [$want, $root, $ex, $what]) {
    check(Status::includesSignIn($root, $ex) === $want, "includesSignIn(): {$what}");
}

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
