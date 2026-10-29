<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Period;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class PeriodResolver
{
    public static function resolve(
        PeriodPreset $preset,
        CarbonImmutable $today,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?string $nowTime = null,
    ): Period {
        $today = $today->startOfDay();

        [$periodFrom, $periodTo] = match ($preset) {
            PeriodPreset::today => [$today, $today],
            PeriodPreset::yesterday => [$today->subDay(), $today->subDay()],
            PeriodPreset::this_week => [$today->startOfWeek(CarbonInterface::SATURDAY)->startOfDay(), $today],
            PeriodPreset::last_week => [
                $today->startOfWeek(CarbonInterface::SATURDAY)->subDays(7)->startOfDay(),
                $today->startOfWeek(CarbonInterface::SATURDAY)->subDay()->startOfDay(),
            ],
            PeriodPreset::this_month => [$today->startOfMonth()->startOfDay(), $today],
            PeriodPreset::last_month => [
                $today->startOfMonth()->subDay()->startOfMonth()->startOfDay(),
                $today->startOfMonth()->subDay()->startOfDay(),
            ],
            PeriodPreset::this_quarter => [$today->startOfQuarter()->startOfDay(), $today],
            PeriodPreset::this_year => [$today->startOfYear()->startOfDay(), $today],
            PeriodPreset::last_30_days => [$today->subDays(29)->startOfDay(), $today],
            PeriodPreset::last_8_weeks => [
                $today->startOfWeek(CarbonInterface::SATURDAY)->subWeeks(7)->startOfDay(),
                $today,
            ],
            PeriodPreset::custom => self::resolveCustom($from, $to),
        };

        $cutoff = ($periodTo->toDateString() === $today->toDateString()) ? $nowTime : null;

        return new Period($periodFrom, $periodTo, $cutoff);
    }

    public static function defaultComparison(PeriodPreset $preset): ComparisonMode
    {
        return match ($preset) {
            PeriodPreset::today,
            PeriodPreset::yesterday,
            PeriodPreset::this_week,
            PeriodPreset::last_week => ComparisonMode::same_weekday_last_week,

            PeriodPreset::this_quarter,
            PeriodPreset::this_year => ComparisonMode::same_period_last_year,

            default => ComparisonMode::previous,
        };
    }

    public static function comparison(
        Period $current,
        ComparisonMode $mode,
        ?PeriodPreset $preset = null,
    ): ?Period {
        return match ($mode) {
            ComparisonMode::none => null,
            ComparisonMode::same_weekday_last_week => $current->shift(-7),
            ComparisonMode::same_period_last_year => $current->shift(-364),
            ComparisonMode::previous => self::resolvePreviousComparison($current, $preset),
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function resolveCustom(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('Custom preset requires both from and to dates.');
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private static function resolvePreviousComparison(Period $current, ?PeriodPreset $preset): Period
    {
        if ($preset !== null && in_array($preset, [
            PeriodPreset::this_week,
            PeriodPreset::this_month,
            PeriodPreset::this_quarter,
            PeriodPreset::this_year,
        ], true)) {
            [$prevUnitStart, $prevUnitEnd] = match ($preset) {
                PeriodPreset::this_week => [
                    $current->from->subDays(7)->startOfDay(),
                    $current->from->subDay()->startOfDay(),
                ],
                PeriodPreset::this_month => [
                    $current->from->subDay()->startOfMonth()->startOfDay(),
                    $current->from->subDay()->startOfDay(),
                ],
                PeriodPreset::this_quarter => [
                    $current->from->subDay()->startOfQuarter()->startOfDay(),
                    $current->from->subDay()->startOfDay(),
                ],
                PeriodPreset::this_year => [
                    $current->from->subDay()->startOfYear()->startOfDay(),
                    $current->from->subDay()->startOfDay(),
                ],
            };

            $elapsedOffsetDays = $current->days() - 1;
            $targetTo = $prevUnitStart->addDays($elapsedOffsetDays)->startOfDay();
            $comparisonTo = ($targetTo > $prevUnitEnd) ? $prevUnitEnd : $targetTo;

            return new Period($prevUnitStart, $comparisonTo, $current->cutoffTime);
        }

        if ($preset !== null && in_array($preset, [
            PeriodPreset::last_week,
            PeriodPreset::last_month,
        ], true)) {
            [$prevUnitStart, $prevUnitEnd] = match ($preset) {
                PeriodPreset::last_week => [
                    $current->from->subDays(7)->startOfDay(),
                    $current->from->subDay()->startOfDay(),
                ],
                PeriodPreset::last_month => [
                    $current->from->subDay()->startOfMonth()->startOfDay(),
                    $current->from->subDay()->startOfDay(),
                ],
            };

            return new Period($prevUnitStart, $prevUnitEnd, $current->cutoffTime);
        }

        // otherwise: immediately preceding range of equal length (to = current.from - 1 day)
        $comparisonTo = $current->from->subDay()->startOfDay();
        $comparisonFrom = $comparisonTo->subDays($current->days() - 1)->startOfDay();

        return new Period($comparisonFrom, $comparisonTo, $current->cutoffTime);
    }
}
