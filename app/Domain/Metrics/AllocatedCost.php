<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Period\Period;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AllocatedCost
{
    /**
     * Compute allocated labor cost for selected branches over the period.
     *
     * @param  array<int>  $branchIds
     */
    public static function labor(array $branchIds, Period $p): float
    {
        return self::calculate($branchIds, $p, 'labor', 'labor_cost');
    }

    /**
     * Compute allocated rent cost for selected branches over the period.
     *
     * @param  array<int>  $branchIds
     */
    public static function rent(array $branchIds, Period $p): float
    {
        return self::calculate($branchIds, $p, 'rent', 'rent_cost');
    }

    /**
     * @param  array<int>  $branchIds
     */
    private static function calculate(array $branchIds, Period $p, string $category, string $column): float
    {
        if (empty($branchIds)) {
            return 0.0;
        }

        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        $totalAllocated = 0.0;

        $from = $p->from->startOfDay();
        $to = $p->to->startOfDay();

        // 1. Identify all months overlapping the period
        $months = [];
        $curr = $from->startOfMonth();
        while ($curr <= $to) {
            $monthStart = $curr->startOfMonth();
            $monthEnd = $curr->endOfMonth()->startOfDay();
            $daysInMonth = (int) $curr->daysInMonth;

            $overlapStart = $from > $monthStart ? $from : $monthStart;
            $overlapEnd = $to < $monthEnd ? $to : $monthEnd;
            $overlapDays = (int) $overlapStart->diffInDays($overlapEnd) + 1;

            $months[] = [
                'start' => $monthStart,
                'end' => $monthEnd,
                'days_in_month' => $daysInMonth,
                'overlap_days' => $overlapDays,
                'is_past' => $monthEnd < $today,
            ];

            $curr = $curr->addMonthNoOverflow()->startOfMonth();
        }

        // 2. Compute allocation for each branch and each month
        foreach ($branchIds as $branchId) {
            foreach ($months as $m) {
                $monthStart = $m['start'];
                $monthEnd = $m['end'];
                $daysInMonth = $m['days_in_month'];
                $overlapDays = $m['overlap_days'];
                $isPast = $m['is_past'];

                $monthlyBasis = null;
                $sumExpr = match ($column) {
                    'labor_cost' => 'COALESCE(SUM(labor_cost), 0) as total',
                    'rent_cost' => 'COALESCE(SUM(rent_cost), 0) as total',
                    default => '0 as total',
                };

                // Rule 1: actual Σ cost of that month if the month is fully in the past
                if ($isPast) {
                    $row = DB::table('agg_branch_daily')
                        ->where('branch_id', $branchId)
                        ->whereBetween('business_date', [
                            $monthStart->toDateString(),
                            $monthEnd->toDateString(),
                        ])
                        ->selectRaw($sumExpr)
                        ->first();

                    $monthlyBasis = (float) ($row->total ?? 0.0);
                } else {
                    // Rule 2: Σ avg_month_rate of that branch's dim_expense_types with category 'labor' / 'rent'
                    $dimRow = DB::table('dim_expense_types')
                        ->where('branch_id', $branchId)
                        ->whereNull('deleted_at')
                        ->whereRaw("COALESCE(NULLIF(category_override, ''), category) = ?", [$category])
                        ->whereNotNull('avg_month_rate')
                        ->selectRaw('SUM(avg_month_rate) as total_rate, COUNT(avg_month_rate) as rate_count')
                        ->first();

                    if ($dimRow !== null && ((int) $dimRow->rate_count) > 0) {
                        $monthlyBasis = (float) $dimRow->total_rate;
                    } else {
                        // Rule 3: trailing 3-month average actual
                        $trailingEnd = $monthStart->subDay()->startOfDay();
                        $trailingStart = $monthStart->subMonths(3)->startOfMonth()->startOfDay();

                        $trailingRow = DB::table('agg_branch_daily')
                            ->where('branch_id', $branchId)
                            ->whereBetween('business_date', [
                                $trailingStart->toDateString(),
                                $trailingEnd->toDateString(),
                            ])
                            ->selectRaw($sumExpr)
                            ->first();

                        $monthlyBasis = ((float) ($trailingRow->total ?? 0.0)) / 3.0;
                    }
                }

                $allocated = $monthlyBasis * ($overlapDays / $daysInMonth);
                $totalAllocated += $allocated;
            }
        }

        return round($totalAllocated, 2);
    }
}
