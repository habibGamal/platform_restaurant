<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Period keys the nightly build writes: complete months as 'YYYY-MM', the current month to
 * yesterday, and 'rolling_90d'. Bounds are inclusive business dates (start of day).
 */
final class PeriodKeys
{
    public const ROLLING_90D = 'rolling_90d';

    /**
     * @return list<string>
     */
    public static function forBuild(CarbonImmutable $today): array
    {
        $month = $today->startOfMonth();
        $keys = [];

        for ($i = (int) config('menu.intelligence.months_back'); $i >= 1; $i--) {
            $keys[] = $month->subMonthsNoOverflow($i)->format('Y-m');
        }

        // On the 1st the month-to-date window would be empty (first..yesterday is inverted).
        if ($today->day > 1) {
            $keys[] = $month->format('Y-m');
        }

        $keys[] = self::ROLLING_90D;

        return $keys;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $key, CarbonImmutable $today): array
    {
        $today = $today->startOfDay();
        $yesterday = $today->subDay();

        if ($key === self::ROLLING_90D) {
            return [$today->subDays(90), $yesterday];
        }

        $first = self::monthStart($key, $today);

        if ($first->greaterThan($yesterday)) {
            throw new InvalidArgumentException("Period [{$key}] has no completed days before {$today->toDateString()}.");
        }

        $last = $first->endOfMonth()->startOfDay();

        return [$first, $last->greaterThan($yesterday) ? $yesterday : $last];
    }

    /**
     * The comparison window: the previous calendar month for a month key, null for rolling_90d
     * (its momentum compares the last 30 days with the 60 before, inside the stats builder).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function previous(string $key, CarbonImmutable $today): ?array
    {
        if ($key === self::ROLLING_90D) {
            return null;
        }

        $first = self::monthStart($key, $today)->subMonthNoOverflow();

        return self::range($first->format('Y-m'), $today);
    }

    /** First day of a 'YYYY-MM' key, in $today's timezone. */
    private static function monthStart(string $key, CarbonImmutable $today): CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $key, $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw new InvalidArgumentException("Unknown period key [{$key}].");
        }

        return $today->setDate((int) $m[1], (int) $m[2], 1)->startOfDay();
    }
}
