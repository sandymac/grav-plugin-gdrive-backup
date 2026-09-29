<?php

declare(strict_types=1);

namespace Grav\Plugin\GdriveBackup;

/**
 * Which backups to keep, as pure functions of their timestamps. Mirrors the
 * HA add-on's generational settings: the newest `keep`, plus the newest
 * backup in each of the last N calendar days, ISO weeks, months and years
 * (counted back from $now, the current period included). Times are read in
 * PHP's default timezone, the one Grav wrote the filenames in.
 */
final class Retention
{
    /** Grav's `<profile>--YmdHis.zip`, stricter than core's regex so stray names never parse. */
    public const FILENAME = '/^(.+)--(\d{14})\.zip$/';

    private const BUCKETS = [
        // unit => [date() key format, period start relative to today, step]
        'days' => ['Y-m-d', 'today', 'day'],
        'weeks' => ['o-W', 'monday this week', 'week'],
        'months' => ['Y-m', 'first day of this month', 'month'],
        'years' => ['Y', 'first day of january this year', 'year'],
    ];

    /**
     * @param int[] $timestamps
     * @param array<string, int|string> $generational {days, weeks, months, years}
     * @return int[] the kept timestamps, newest first
     */
    public static function keep(array $timestamps, int $keep, array $generational, ?int $now = null): array
    {
        $all = array_values(array_unique(array_map('intval', $timestamps)));
        rsort($all);
        $kept = array_slice($all, 0, max(0, $keep));

        $today = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        foreach (self::BUCKETS as $unit => [$format, $start, $step]) {
            $n = (int) ($generational[$unit] ?? 0);
            $base = $today->modify($start)->setTime(12, 0); // noon: DST shifts never cross a day
            $open = [];
            for ($i = 0; $i < $n; $i++) {
                $open[$base->modify("-{$i} {$step}")->format($format)] = true;
            }
            foreach ($all as $ts) { // newest first, so the first hit per bucket is its newest
                $key = date($format, $ts);
                if (isset($open[$key])) {
                    unset($open[$key]);
                    $kept[] = $ts;
                }
            }
        }

        $kept = array_values(array_unique($kept));
        rsort($kept);

        return $kept;
    }

    /** The backup time encoded in a Grav backup filename, or null if it isn't one. */
    public static function timestamp(string $filename): ?int
    {
        if (preg_match(self::FILENAME, $filename, $m) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!YmdHis', $m[2]);

        return $date !== false && $date->format('YmdHis') === $m[2] ? $date->getTimestamp() : null;
    }
}
