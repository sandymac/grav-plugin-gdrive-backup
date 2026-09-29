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
use Grav\Plugin\GdriveBackup\Retention;
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

// Folder choice: configured, remembered, or created.
$drive = new Drive($creds, [Drive::SCOPE_FILE], $fake = new FakeDrive());
check(Sync::folder($drive, 'CFG', 'OLD', 'example.com') === 'CFG', 'a configured folder id wins');
check(Sync::folder($drive, '', 'OLD', 'example.com') === 'OLD', 'a remembered folder is reused while it exists');
$fake->files = [];
$ensure = new Drive($creds, [Drive::SCOPE_FILE], static function (string $m, string $u, array $o) use ($fake): array {
    return $m === 'POST' ? [200, '{"id":"NEWFOLDER"}', []] : $fake($m, $u, $o);
});
check(Sync::folder($ensure, '', 'GONE', 'example.com') === 'NEWFOLDER' && Sync::folder($ensure, '', 'TRASHED', 'example.com') === 'NEWFOLDER', 'a missing or trashed remembered folder is recreated');

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
check(str_contains((string) end($fake->queries), "name='Grav backups (example.com)'"), 'the created folder is named after the site');

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
