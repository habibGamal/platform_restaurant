### 4. Public API Shipped

```php
namespace App\Domain\Metrics;

final readonly class KpiValues
{
    public int $orders_count;
    public int $cancelled_count;
    public float $gross_sales;
    public float $discounts;
    public float $tax;
    public float $service;
    public float $net_sales;
    public float $returns_amount;
    public float $net_sales_after_returns;
    public float $cogs;
    public float $returned_cogs_recovered;
    public float $gross_profit;
    public float $expenses_total;
    public float $labor_cost;
    public float $rent_cost;
    public float $other_expenses;
    public float $waste_cost;
    public float $inventory_variance_cost;
    public float $purchases_total;
    public float $purchase_returns_total;
    public float $net_profit;
    public int $shifts_count;
    public float $cash_deficit_total;
    public int $days_with_data;

    public function avgTicket(): ?float;
    public function grossMargin(): ?float;
    public function netMargin(): ?float;
    public function foodCostPct(): ?float;
    public function wastePct(): ?float;
    public function wasteShareOfUsage(): ?float;
    public function laborPct(float $allocatedLabor): ?float;
    public function primeCostPct(float $allocatedLabor): ?float;
    public function netPurchases(): float;
    public function cancelRate(): ?float;
    public function returnRate(): ?float;
    public static function zero(): self;
    public function plus(self $other): self;
    public static function fromRow(object|array $row): self;
}

final class Kpi
{
    public static function growth(?float $current, ?float $previous): ?float;
    public static function isGoodDirection(string $kpi, float $delta): bool;
}

final class MetricsQuery
{
    public readonly User $user;
    public readonly ReportFilters $filters;

    public static function for(User $user, ReportFilters $filters): self;
    public function user(): User;
    public function filters(): ReportFilters;
    /** @return list<int> */
    public function branchIds(): array;
    public function canSeeGroup(): bool;
    public function canSeeOtherBranchNames(): bool;
    public function scoped(string $table, ?Period $period = null): \Illuminate\Database\Query\Builder;
    public function totals(?Period $period = null): KpiValues;
    public function comparisonTotals(): ?KpiValues;
    /** @return array<int, KpiValues> */
    public function byBranch(?Period $period = null): array;
    /**
     * @return ($perBranch is true ? array<int, array<string, KpiValues>> : array<string, KpiValues>)
     */
    public function series(?Granularity $g = null, ?Period $period = null, bool $perBranch = false): array;
    /** @return array{orders: int, net_sales: float} */
    public function hourlyTotals(Period $p, string $untilTime): array;
}

final readonly class CompletenessResult
{
    public function statusFor(int $branchId, string $date): string; // 'complete'|'closed'|'open'|'missing'|'not_opened'
    public function isBranchComplete(int $branchId): bool;
    /** @return array<int, array{complete: int, total: int, is_complete: bool}> */
    public function perBranchCoverage(): array;
    /** @return array{complete: int, total: int} */
    public function summary(): array;
}

final class Completeness
{
    public static function for(MetricsQuery $q, Period $p): CompletenessResult;
    public static function isBranchComplete(int $branchId, Period $p): bool;
}

final class AllocatedCost
{
    /** @param array<int> $branchIds */
    public static function labor(array $branchIds, Period $p): float;
    /** @param array<int> $branchIds */
    public static function rent(array $branchIds, Period $p): float;
}
```

```php
namespace App\Domain\Targets;

final readonly class BranchTargetStatus
{
    public int $branchId;
    public string $metric;
    public ?float $targetMonth;
    public ?float $targetToDate;
    public float $actualToDate;
    public ?float $achievementToDate;
    public ?float $monthAchievement;
    public ?float $gap;
    public ?float $projected;
    public ?float $projectedAchievement;
    public ?float $requiredDailyAverage;
    public ?float $currentDailyAverage;
    public string $status; // 'on_track'|'at_risk'|'behind'|'no_target'|'within'|'above'
}

final class TargetDistributor
{
    /** @return array<string, float> */
    public static function daily(Target $t): array;
    public static function persist(Target $t): void;
}

final class TargetObserver
{
    public function saved(Target $target): void;
}

final class TargetAchievement
{
    /** @return list<BranchTargetStatus> */
    public static function for(MetricsQuery $q, string $metric, CarbonImmutable $today): array;
}
```

