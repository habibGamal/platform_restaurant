<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Tenancy\AccessibleBranches;
use App\Models\Branch;
use App\Models\BranchSet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MetricsQuery
{
    /** @var list<int>|null */
    private ?array $resolvedBranchIds = null;

    public function __construct(
        public readonly User $user,
        public readonly ReportFilters $filters,
    ) {}

    public static function for(User $user, ReportFilters $filters): self
    {
        return new self($user, $filters);
    }

    public function user(): User
    {
        return $this->user;
    }

    public function filters(): ReportFilters
    {
        return $this->filters;
    }

    /**
     * Resolve the target branch IDs based on user access, filters, and like-for-like rules.
     *
     * @return list<int>
     *
     * @throws AuthorizationException
     */
    public function branchIds(): array
    {
        if ($this->resolvedBranchIds !== null) {
            return $this->resolvedBranchIds;
        }

        $accessibleBranchIds = AccessibleBranches::ids($this->user);

        // Explicit selections must be authorized before combining the scope filters.
        if ($this->filters->branchIds !== null && count($this->filters->branchIds) > 0) {
            $diff = array_diff($this->filters->branchIds, $accessibleBranchIds);
            if (! empty($diff)) {
                throw new AuthorizationException('User does not have access to one or more requested branches.');
            }
            $candidateIds = $this->filters->branchIds;
        } else {
            $candidateIds = $accessibleBranchIds;
        }

        if ($this->filters->branchSetId !== null) {
            $branchSet = BranchSet::withoutGlobalScopes()
                ->where('id', $this->filters->branchSetId)
                ->where('organization_id', $this->user->organization_id)
                ->where(function ($q): void {
                    $q->whereNull('user_id')->orWhere('user_id', $this->user->id);
                })
                ->first();

            if ($branchSet !== null) {
                $setIds = $branchSet->branches()
                    ->pluck('branches.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
            } else {
                $setIds = [];
            }
            $candidateIds = array_values(array_intersect($candidateIds, $setIds));
        }

        if ($this->filters->restaurantId !== null) {
            $restaurantIds = Branch::withoutGlobalScopes()
                ->where('organization_id', $this->user->organization_id)
                ->where('restaurant_id', $this->filters->restaurantId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $candidateIds = array_values(array_intersect($candidateIds, $restaurantIds));
        }

        // ALWAYS intersect with accessible branches
        $resolved = array_values(array_intersect($candidateIds, $accessibleBranchIds));

        // Like-for-like: keep only branches having agg_branch_daily rows in BOTH current and comparison periods
        if ($this->filters->likeForLike && $this->filters->comparison !== null && ! empty($resolved)) {
            $currentBranches = DB::table('agg_branch_daily')
                ->whereIn('branch_id', $resolved)
                ->whereBetween('business_date', [
                    $this->filters->period->from->toDateString(),
                    $this->filters->period->to->toDateString(),
                ])
                ->distinct()
                ->pluck('branch_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $comparisonBranches = DB::table('agg_branch_daily')
                ->whereIn('branch_id', $resolved)
                ->whereBetween('business_date', [
                    $this->filters->comparison->from->toDateString(),
                    $this->filters->comparison->to->toDateString(),
                ])
                ->distinct()
                ->pluck('branch_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $resolved = array_values(array_intersect($resolved, $currentBranches, $comparisonBranches));
        }

        sort($resolved);

        /** @var list<int> $resolved */
        $this->resolvedBranchIds = array_values(array_unique($resolved));

        return $this->resolvedBranchIds;
    }

    public function canSeeGroup(): bool
    {
        return $this->user->can('analytics.view_group');
    }

    public function canSeeOtherBranchNames(): bool
    {
        return $this->user->can('analytics.view_other_branch_names');
    }

    /**
     * Get a query builder scoped to selected branches and business_date within the period.
     */
    public function scoped(string $table, ?Period $period = null): Builder
    {
        if (! str_starts_with($table, 'agg_') && $table !== 'wh_orders') {
            throw new InvalidArgumentException(sprintf("Table must start with 'agg_' or be 'wh_orders', got '%s'.", $table));
        }

        $period = $period ?? $this->filters->period;
        $branchIds = $this->branchIds();

        return DB::table($table)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [
                $period->from->toDateString(),
                $period->to->toDateString(),
            ]);
    }

    /**
     * Total KPI values across all selected branches for the period.
     */
    public function totals(?Period $period = null): KpiValues
    {
        $period = $period ?? $this->filters->period;
        $branchIds = $this->branchIds();

        if (empty($branchIds)) {
            return KpiValues::zero();
        }

        $row = $this->scoped('agg_branch_daily', $period)
            ->selectRaw('
                COUNT(DISTINCT business_date) as days_with_data,
                COALESCE(SUM(orders_count), 0) as orders_count,
                COALESCE(SUM(cancelled_count), 0) as cancelled_count,
                COALESCE(SUM(gross_sales), 0) as gross_sales,
                COALESCE(SUM(discounts), 0) as discounts,
                COALESCE(SUM(tax), 0) as tax,
                COALESCE(SUM(service), 0) as service,
                COALESCE(SUM(net_sales), 0) as net_sales,
                COALESCE(SUM(returns_amount), 0) as returns_amount,
                COALESCE(SUM(net_sales_after_returns), 0) as net_sales_after_returns,
                COALESCE(SUM(cogs), 0) as cogs,
                COALESCE(SUM(returned_cogs_recovered), 0) as returned_cogs_recovered,
                COALESCE(SUM(gross_profit), 0) as gross_profit,
                COALESCE(SUM(expenses_total), 0) as expenses_total,
                COALESCE(SUM(labor_cost), 0) as labor_cost,
                COALESCE(SUM(rent_cost), 0) as rent_cost,
                COALESCE(SUM(other_expenses), 0) as other_expenses,
                COALESCE(SUM(waste_cost), 0) as waste_cost,
                COALESCE(SUM(inventory_variance_cost), 0) as inventory_variance_cost,
                COALESCE(SUM(purchases_total), 0) as purchases_total,
                COALESCE(SUM(purchase_returns_total), 0) as purchase_returns_total,
                COALESCE(SUM(net_profit), 0) as net_profit,
                COALESCE(SUM(shifts_count), 0) as shifts_count,
                COALESCE(SUM(cash_deficit_total), 0) as cash_deficit_total
            ')
            ->first();

        if (! $row) {
            return KpiValues::zero();
        }

        return KpiValues::fromRow($row);
    }

    /**
     * Total KPI values for the comparison period (null if no comparison period is set).
     */
    public function comparisonTotals(): ?KpiValues
    {
        if ($this->filters->comparison === null) {
            return null;
        }

        return $this->totals($this->filters->comparison);
    }

    /**
     * KPI totals grouped by branch ID. Every selected branch is present, with zero() when no rows.
     *
     * @return array<int, KpiValues>
     */
    public function byBranch(?Period $period = null): array
    {
        $period = $period ?? $this->filters->period;
        $branchIds = $this->branchIds();

        $result = [];
        foreach ($branchIds as $bId) {
            $result[$bId] = KpiValues::zero();
        }

        if (empty($branchIds)) {
            return $result;
        }

        $rows = $this->scoped('agg_branch_daily', $period)
            ->groupBy('branch_id')
            ->selectRaw('
                branch_id,
                COUNT(DISTINCT business_date) as days_with_data,
                COALESCE(SUM(orders_count), 0) as orders_count,
                COALESCE(SUM(cancelled_count), 0) as cancelled_count,
                COALESCE(SUM(gross_sales), 0) as gross_sales,
                COALESCE(SUM(discounts), 0) as discounts,
                COALESCE(SUM(tax), 0) as tax,
                COALESCE(SUM(service), 0) as service,
                COALESCE(SUM(net_sales), 0) as net_sales,
                COALESCE(SUM(returns_amount), 0) as returns_amount,
                COALESCE(SUM(net_sales_after_returns), 0) as net_sales_after_returns,
                COALESCE(SUM(cogs), 0) as cogs,
                COALESCE(SUM(returned_cogs_recovered), 0) as returned_cogs_recovered,
                COALESCE(SUM(gross_profit), 0) as gross_profit,
                COALESCE(SUM(expenses_total), 0) as expenses_total,
                COALESCE(SUM(labor_cost), 0) as labor_cost,
                COALESCE(SUM(rent_cost), 0) as rent_cost,
                COALESCE(SUM(other_expenses), 0) as other_expenses,
                COALESCE(SUM(waste_cost), 0) as waste_cost,
                COALESCE(SUM(inventory_variance_cost), 0) as inventory_variance_cost,
                COALESCE(SUM(purchases_total), 0) as purchases_total,
                COALESCE(SUM(purchase_returns_total), 0) as purchase_returns_total,
                COALESCE(SUM(net_profit), 0) as net_profit,
                COALESCE(SUM(shifts_count), 0) as shifts_count,
                COALESCE(SUM(cash_deficit_total), 0) as cash_deficit_total
            ')
            ->get();

        foreach ($rows as $row) {
            $branchId = (int) $row->branch_id;
            $result[$branchId] = KpiValues::fromRow($row);
        }

        return $result;
    }

    /**
     * Ordered array keyed by bucket start date 'Y-m-d' -> KpiValues,
     * or [branchId => [bucket => KpiValues]] when perBranch = true.
     * Buckets with no data are present with zero values.
     *
     * @return ($perBranch is true ? array<int, array<string, KpiValues>> : array<string, KpiValues>)
     */
    public function series(?Granularity $g = null, ?Period $period = null, bool $perBranch = false): array
    {
        $g = $g ?? $this->filters->granularity;
        $period = $period ?? $this->filters->period;
        $branchIds = $this->branchIds();

        // 1. Establish ordered bucket keys across the period dates
        $bucketKeys = [];
        foreach ($period->dates() as $date) {
            $start = $g->bucketStart($date)->toDateString();
            if (! isset($bucketKeys[$start])) {
                $bucketKeys[$start] = true;
            }
        }
        $orderedBuckets = array_keys($bucketKeys);

        // 2. Initialize default zero structure
        if ($perBranch) {
            /** @var array<int, array<string, KpiValues>> $result */
            $result = [];
            foreach ($branchIds as $branchId) {
                $result[$branchId] = [];
                foreach ($orderedBuckets as $bucket) {
                    $result[$branchId][$bucket] = KpiValues::zero();
                }
            }
        } else {
            /** @var array<string, KpiValues> $result */
            $result = [];
            foreach ($orderedBuckets as $bucket) {
                $result[$bucket] = KpiValues::zero();
            }
        }

        if (empty($branchIds)) {
            return $result;
        }

        // 3. Query all raw aggregate rows
        $rows = $this->scoped('agg_branch_daily', $period)->get();

        // 4. Map each row to its bucket and aggregate
        foreach ($rows as $row) {
            $branchId = (int) $row->branch_id;
            $date = CarbonImmutable::parse($row->business_date);
            $bucket = $g->bucketStart($date)->toDateString();

            if (! in_array($bucket, $orderedBuckets, true)) {
                continue;
            }

            $kpi = KpiValues::fromRow($row);

            if ($perBranch) {
                if (isset($result[$branchId][$bucket])) {
                    $result[$branchId][$bucket] = $result[$branchId][$bucket]->plus($kpi);
                }
            } else {
                if (isset($result[$bucket])) {
                    $result[$bucket] = $result[$bucket]->plus($kpi);
                }
            }
        }

        return $result;
    }

    /**
     * Hourly totals from agg_branch_hourly up to the specified hour string (e.g. '14:00' or '14').
     *
     * @return array{orders: int, net_sales: float}
     */
    public function hourlyTotals(Period $p, string $untilTime): array
    {
        $branchIds = $this->branchIds();
        if (empty($branchIds)) {
            return ['orders' => 0, 'net_sales' => 0.0];
        }

        $targetHour = (int) explode(':', $untilTime)[0];

        $row = DB::table('agg_branch_hourly')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [
                $p->from->toDateString(),
                $p->to->toDateString(),
            ])
            ->where('hour', '<=', $targetHour)
            ->selectRaw('COALESCE(SUM(orders_count), 0) as orders, COALESCE(SUM(net_sales), 0) as net_sales')
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'net_sales' => (float) ($row->net_sales ?? 0.0),
        ];
    }
}
