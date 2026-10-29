<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Period\Period;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Completeness
{
    /**
     * Compute completeness for a metrics query over a given period.
     */
    public static function for(MetricsQuery $q, Period $p): CompletenessResult
    {
        $branchIds = $q->branchIds();
        $today = CarbonImmutable::now('Africa/Cairo')->toDateString();

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id')
            ->all();

        $dailyRows = [];
        if (! empty($branchIds)) {
            $rows = DB::table('agg_branch_daily')
                ->whereIn('branch_id', $branchIds)
                ->whereBetween('business_date', [
                    $p->from->toDateString(),
                    $p->to->toDateString(),
                ])
                ->get();

            foreach ($rows as $row) {
                $dailyRows["{$row->branch_id}:{$row->business_date}"] = $row;
            }
        }

        return new CompletenessResult(
            branchIds: $branchIds,
            period: $p,
            today: $today,
            branches: $branches,
            dailyRows: $dailyRows,
        );
    }

    /**
     * Check whether a specific branch is complete over the given period.
     */
    public static function isBranchComplete(int $branchId, Period $p): bool
    {
        $today = CarbonImmutable::now('Africa/Cairo')->toDateString();

        $branch = Branch::withoutGlobalScopes()->find($branchId);
        $branches = $branch !== null ? [$branchId => $branch] : [];

        $rows = DB::table('agg_branch_daily')
            ->where('branch_id', $branchId)
            ->whereBetween('business_date', [
                $p->from->toDateString(),
                $p->to->toDateString(),
            ])
            ->get();

        $dailyRows = [];
        foreach ($rows as $row) {
            $dailyRows["{$row->branch_id}:{$row->business_date}"] = $row;
        }

        $result = new CompletenessResult(
            branchIds: [$branchId],
            period: $p,
            today: $today,
            branches: $branches,
            dailyRows: $dailyRows,
        );

        return $result->isBranchComplete($branchId);
    }
}
