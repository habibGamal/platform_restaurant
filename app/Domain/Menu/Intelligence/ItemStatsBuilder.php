<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

use App\Domain\Menu\ItemKey;
use App\Enums\BranchStatus;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Per-period item stats for every branch scope (0 = all branches, plus each active branch) and
 * channel ('all' plus each order type sold in the range). Velocity is normalised by the open days
 * of the branches that sold the item, so a closed branch or a late launch does not read as weak demand.
 *
 * A handful of grouped queries per period, never per item. Runs in queue jobs without a user:
 * every query is org-filtered by hand.
 */
final class ItemStatsBuilder
{
    public const CHANNEL_ALL = 'all';

    /** Repeat share needs at least this many orders with a known customer. */
    private const MIN_REPEAT_ORDERS = 10;

    /** rolling_90d momentum: the last 30 days against the 60 before them. */
    private const ROLLING_RECENT_DAYS = 30;

    /** decimal(10,4) ceiling for mom_velocity_change. */
    private const MAX_MOM = 999999.9999;

    private const ORDER_ITEM_KEY = 'COALESCE(i.product_ref, i.product_uuid)';

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE;

    /** Number of rows written for the period (its previous rows are replaced). */
    public function build(Organization $org, string $periodKey, CarbonImmutable $today, string $buildId): int
    {
        $orgId = $org->id;
        [$from, $to] = PeriodKeys::range($periodKey, $today);

        $branches = DB::table('branches')->where('organization_id', $orgId)->get(['id', 'status']);
        $branchIds = array_values($branches->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $activeIds = array_values($branches->where('status', BranchStatus::ACTIVE->value)->pluck('id')->map(fn ($id): int => (int) $id)->all());

        if ($branchIds === []) {
            return IntelligenceWriter::replace('item_period_stats', $orgId, $periodKey, []);
        }

        $volumes = $this->volumes($orgId, $branchIds, $from, $to);

        if ($periodKey === PeriodKeys::ROLLING_90D) {
            $recentFrom = $to->subDays(self::ROLLING_RECENT_DAYS - 1);
            $recent = $this->volumes($orgId, $branchIds, $recentFrom, $to);
            $baseline = $this->volumes($orgId, $branchIds, $from, $recentFrom->subDay());
        } else {
            $previous = PeriodKeys::previous($periodKey, $today);
            $recent = $volumes;
            $baseline = $previous === null ? [] : $this->volumes($orgId, $branchIds, $previous[0], $previous[1]);
        }

        $daily = $this->dailyTotals($orgId, $branchIds, $from, $to);
        $orders = $this->orderStats($orgId, $branchIds, $from, $to);

        $rows = [];
        foreach ([0, ...$activeIds] as $scope) {
            $scopeOrders = $orders[$scope] ?? [];

            foreach ($daily[$scope] ?? [] as $itemKey => $totals) {
                $itemKey = (string) $itemKey; // numeric refs come back as int array keys
                $byType = [];
                foreach ($scopeOrders as $type => $items) {
                    if (isset($items[$itemKey])) {
                        $byType[$type] = $items[$itemKey];
                    }
                }

                $mix = array_map(fn (array $stats): int => $stats['orders'], $byType);
                $rows[] = $this->row($scope, self::CHANNEL_ALL, $itemKey, $totals, self::sumOrderStats($byType), $mix, $volumes, $recent, $baseline, $buildId);
            }

            foreach ($scopeOrders as $type => $items) {
                foreach ($items as $itemKey => $stats) {
                    $itemKey = (string) $itemKey;
                    $totals = [
                        'qty' => $stats['qty'],
                        'net_sales' => $stats['net_sales'],
                        'cogs' => $stats['cogs'],
                        'gross_profit' => $stats['net_sales'] - $stats['cogs'],
                        'return_qty' => 0.0,
                        'waste_cost' => 0.0,
                        'days_sold' => $stats['days_sold'],
                    ];
                    $rows[] = $this->row($scope, $type, $itemKey, $totals, $stats, null, $volumes, $recent, $baseline, $buildId);
                }
            }
        }

        return IntelligenceWriter::replace('item_period_stats', $orgId, $periodKey, $rows);
    }

    /**
     * @param  array{qty: float, net_sales: float, cogs: float, gross_profit: float, return_qty: float, waste_cost: float, days_sold: int}  $totals
     * @param  array{orders: int, multi: int, daypart: array<string, int>, weekday: array<int, int>, known: int, repeaters: int}  $stats
     * @param  array<string, int>|null  $mix  order count per order type (channel 'all' only)
     * @param  array<int, array<string, array<string, array{qty: float, open_days: int}>>>  $volumes
     * @param  array<int, array<string, array<string, array{qty: float, open_days: int}>>>  $recent
     * @param  array<int, array<string, array<string, array{qty: float, open_days: int}>>>  $baseline
     * @return array<string, mixed>
     */
    private function row(int $scope, string $channel, string $itemKey, array $totals, array $stats, ?array $mix, array $volumes, array $recent, array $baseline, string $buildId): array
    {
        $openDays = $volumes[$scope][$channel][$itemKey]['open_days'] ?? 0;
        $orders = $stats['orders'];

        return [
            'branch_id' => $scope,
            'channel' => $channel,
            'item_key' => $itemKey,
            'qty' => round($totals['qty'], 3),
            'net_sales' => round($totals['net_sales'], 2),
            'cogs' => round($totals['cogs'], 2),
            'gross_profit' => round($totals['gross_profit'], 2),
            'open_days' => $openDays,
            'days_sold' => $totals['days_sold'],
            'velocity' => $openDays > 0 ? round($totals['qty'] / $openDays, 4) : 0.0,
            'orders' => $orders,
            'attach_rate' => $orders > 0 ? round($stats['multi'] / $orders, 4) : null,
            'daypart' => self::jsonObject(self::shares($stats['daypart'])),
            'weekday' => self::jsonObject(self::shares($stats['weekday'])),
            'channel_mix' => $mix === null ? null : self::jsonObject(self::shares($mix)),
            'repeat_customer_share' => $stats['known'] >= self::MIN_REPEAT_ORDERS ? round($stats['repeaters'] / $stats['known'], 4) : null,
            'return_qty' => round($totals['return_qty'], 3),
            'waste_cost' => round($totals['waste_cost'], 2),
            'mom_velocity_change' => self::momentum($recent[$scope][$channel][$itemKey] ?? null, $baseline[$scope][$channel][$itemKey] ?? null),
            'build_id' => $buildId,
        ];
    }

    /**
     * @param  array{qty: float, open_days: int}|null  $recent
     * @param  array{qty: float, open_days: int}|null  $baseline
     */
    private static function momentum(?array $recent, ?array $baseline): ?float
    {
        $base = $baseline === null ? 0.0 : self::velocity($baseline);
        if ($base <= 0.0) {
            return null;
        }

        $change = ($recent === null ? 0.0 : self::velocity($recent)) / $base - 1;

        return round(min($change, self::MAX_MOM), 4);
    }

    /** @param array{qty: float, open_days: int} $volume */
    private static function velocity(array $volume): float
    {
        return $volume['open_days'] > 0 ? $volume['qty'] / $volume['open_days'] : 0.0;
    }

    /**
     * Qty and open days per scope, channel and item over a date range: the inputs of velocity.
     * open_days sums the open days of only the branches that sold the item (in that channel).
     *
     * @param  non-empty-list<int>  $branchIds
     * @return array<int, array<string, array<string, array{qty: float, open_days: int}>>> scope => channel => item key
     */
    private function volumes(int $orgId, array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $openDays = DB::table('agg_branch_daily')
            ->where('organization_id', $orgId)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->where('orders_count', '>', 0)
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(DISTINCT business_date) AS n')
            ->pluck('n', 'branch_id')
            ->map(fn ($n): int => (int) $n)
            ->all();

        $daily = DB::table('agg_product_daily')
            ->where('organization_id', $orgId)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('branch_id', DB::raw(ItemKey::SQL))
            ->havingRaw('SUM(qty_sold) > 0')
            ->selectRaw("branch_id, '".self::CHANNEL_ALL."' AS channel, ".ItemKey::SQL.' AS item_key, SUM(qty_sold) AS qty')
            ->get();

        $channels = DB::table('wh_orders as o')
            ->join('wh_order_items as i', fn ($join) => $join->on('i.organization_id', '=', 'o.organization_id')->on('i.branch_id', '=', 'o.branch_id')->on('i.order_uuid', '=', 'o.uuid'))
            ->where('o.organization_id', $orgId)
            ->whereIn('o.branch_id', $branchIds)
            ->whereBetween('o.business_date', [$from->toDateString(), $to->toDateString()])
            ->where('o.status', 'completed')
            ->whereNull('o.deleted_at')
            ->whereRaw(self::ORDER_ITEM_KEY.' IS NOT NULL')
            ->groupBy('o.branch_id', 'o.type', DB::raw(self::ORDER_ITEM_KEY))
            ->selectRaw('o.branch_id, o.type AS channel, '.self::ORDER_ITEM_KEY.' AS item_key, SUM(i.quantity) AS qty')
            ->get();

        $out = [];
        foreach ([...$daily, ...$channels] as $row) {
            /** @var stdClass $row */
            $branchId = (int) $row->branch_id;
            $channel = (string) $row->channel;
            $key = (string) $row->item_key;
            $qty = (float) $row->qty;
            $days = $openDays[$branchId] ?? 0;

            $out[$branchId][$channel][$key] = ['qty' => $qty, 'open_days' => $days];
            $all = $out[0][$channel][$key] ?? ['qty' => 0.0, 'open_days' => 0];
            $out[0][$channel][$key] = ['qty' => $all['qty'] + $qty, 'open_days' => $all['open_days'] + $days];
        }

        return $out;
    }

    /**
     * Channel-'all' totals from agg_product_daily, per branch and for all branches together.
     *
     * @param  non-empty-list<int>  $branchIds
     * @return array<int, array<string, array{qty: float, net_sales: float, cogs: float, gross_profit: float, return_qty: float, waste_cost: float, days_sold: int}>>
     */
    private function dailyTotals(int $orgId, array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach ([true, false] as $byBranch) {
            $query = DB::table('agg_product_daily')
                ->where('organization_id', $orgId)
                ->whereIn('branch_id', $branchIds)
                ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
                ->havingRaw('SUM(qty_sold) > 0')
                ->selectRaw(($byBranch ? 'branch_id' : '0').' AS scope, '.ItemKey::SQL.' AS item_key,
                    SUM(qty_sold) AS qty, SUM(net_sales) AS net_sales, SUM(cogs) AS cogs, SUM(gross_profit) AS gross_profit,
                    SUM(returns_qty) AS return_qty, SUM(waste_cost) AS waste_cost,
                    COUNT(DISTINCT CASE WHEN qty_sold > 0 THEN business_date END) AS days_sold');
            $byBranch ? $query->groupBy('branch_id', DB::raw(ItemKey::SQL)) : $query->groupBy(DB::raw(ItemKey::SQL));

            foreach ($query->get() as $row) {
                /** @var stdClass $row */
                $out[(int) $row->scope][(string) $row->item_key] = [
                    'qty' => (float) $row->qty,
                    'net_sales' => (float) $row->net_sales,
                    'cogs' => (float) $row->cogs,
                    'gross_profit' => (float) $row->gross_profit,
                    'return_qty' => (float) $row->return_qty,
                    'waste_cost' => (float) $row->waste_cost,
                    'days_sold' => (int) $row->days_sold,
                ];
            }
        }

        return $out;
    }

    /**
     * Order-level stats per scope, order type and item from completed orders: one pass over
     * (order, item) pairs, once per branch and once for all branches (days_sold and repeat
     * customers are not additive across branches).
     *
     * @param  non-empty-list<int>  $branchIds
     * @return array<int, array<string, array<string, array{orders: int, qty: float, net_sales: float, cogs: float, days_sold: int, multi: int, daypart: array<string, int>, weekday: array<int, int>, known: int, repeaters: int}>>>
     */
    private function orderStats(int $orgId, array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $in = implode(',', array_fill(0, count($branchIds), '?'));
        $filter = fn (string $a): string => "{$a}organization_id = ? AND {$a}branch_id IN ({$in}) AND {$a}business_date BETWEEN ? AND ?
            AND {$a}status = 'completed' AND {$a}deleted_at IS NULL";
        $orderFilter = $filter('');
        $bindings = [$orgId, ...$branchIds, $from->toDateString(), $to->toDateString()];
        $itemKey = self::ORDER_ITEM_KEY;

        // One row per (order, item); n_items = distinct item keys in the order.
        $lines = "SELECT o.id, o.branch_id, o.type, o.business_date, o.hour_of_day, o.weekday, o.customer_hash,
                {$itemKey} AS item_key, SUM(i.quantity) AS qty, SUM(i.total) AS net_sales, SUM(i.quantity * i.unit_cost) AS cogs,
                COUNT(*) OVER (PARTITION BY o.id) AS n_items
            FROM wh_orders o
            JOIN wh_order_items i ON i.organization_id = o.organization_id AND i.branch_id = o.branch_id AND i.order_uuid = o.uuid
            WHERE {$filter('o.')} AND {$itemKey} IS NOT NULL
            GROUP BY o.id, o.branch_id, o.type, o.business_date, o.hour_of_day, o.weekday, o.customer_hash, {$itemKey}";

        $out = [];
        foreach ([true, false] as $byBranch) {
            // Completed orders per known customer in the same branch scope (any items, any channel).
            $customers = $byBranch
                ? "SELECT branch_id, customer_hash, COUNT(*) AS n FROM wh_orders WHERE {$orderFilter} AND customer_hash IS NOT NULL GROUP BY branch_id, customer_hash"
                : "SELECT customer_hash, COUNT(*) AS n FROM wh_orders WHERE {$orderFilter} AND customer_hash IS NOT NULL GROUP BY customer_hash";
            $customerJoin = $byBranch ? 'c.customer_hash = l.customer_hash AND c.branch_id = l.branch_id' : 'c.customer_hash = l.customer_hash';
            $scope = $byBranch ? 'l.branch_id' : '0';
            $groupBy = $byBranch ? 'l.branch_id, l.type, l.item_key' : 'l.type, l.item_key';

            $sql = "SELECT {$scope} AS scope, l.type, l.item_key,
                    COUNT(*) AS orders, SUM(l.qty) AS qty, SUM(l.net_sales) AS net_sales, SUM(l.cogs) AS cogs,
                    COUNT(DISTINCT l.business_date) AS days_sold,
                    SUM(l.n_items >= 2) AS multi,
                    SUM(l.hour_of_day BETWEEN 6 AND 11) AS morning,
                    SUM(l.hour_of_day BETWEEN 12 AND 16) AS lunch,
                    SUM(l.hour_of_day BETWEEN 17 AND 21) AS evening,
                    SUM(l.hour_of_day >= 22 OR l.hour_of_day <= 5) AS late,
                    SUM(l.weekday = 0) AS w0, SUM(l.weekday = 1) AS w1, SUM(l.weekday = 2) AS w2, SUM(l.weekday = 3) AS w3,
                    SUM(l.weekday = 4) AS w4, SUM(l.weekday = 5) AS w5, SUM(l.weekday = 6) AS w6,
                    SUM(l.customer_hash IS NOT NULL) AS known,
                    SUM(c.n >= 2) AS repeaters
                FROM ({$lines}) l
                LEFT JOIN ({$customers}) c ON {$customerJoin}
                GROUP BY {$groupBy}";

            foreach (DB::select($sql, [...$bindings, ...$bindings]) as $row) {
                /** @var stdClass $row */
                $out[(int) $row->scope][(string) $row->type][(string) $row->item_key] = [
                    'orders' => (int) $row->orders,
                    'qty' => (float) $row->qty,
                    'net_sales' => (float) $row->net_sales,
                    'cogs' => (float) $row->cogs,
                    'days_sold' => (int) $row->days_sold,
                    'multi' => (int) $row->multi,
                    'daypart' => [
                        'morning' => (int) $row->morning,
                        'lunch' => (int) $row->lunch,
                        'evening' => (int) $row->evening,
                        'late' => (int) $row->late,
                    ],
                    'weekday' => array_map(fn (int $d): int => (int) $row->{'w'.$d}, range(0, 6)),
                    'known' => (int) $row->known,
                    'repeaters' => (int) $row->repeaters,
                ];
            }
        }

        return $out;
    }

    /**
     * Order stats over every order type: orders belong to one type, so counts add up.
     *
     * @param  array<string, array{orders: int, multi: int, daypart: array<string, int>, weekday: array<int, int>, known: int, repeaters: int}>  $byType
     * @return array{orders: int, multi: int, daypart: array<string, int>, weekday: array<int, int>, known: int, repeaters: int}
     */
    private static function sumOrderStats(array $byType): array
    {
        $sum = [
            'orders' => 0,
            'multi' => 0,
            'daypart' => ['morning' => 0, 'lunch' => 0, 'evening' => 0, 'late' => 0],
            'weekday' => array_fill(0, 7, 0),
            'known' => 0,
            'repeaters' => 0,
        ];

        foreach ($byType as $stats) {
            foreach (['orders', 'multi', 'known', 'repeaters'] as $field) {
                $sum[$field] += $stats[$field];
            }
            foreach ($stats['daypart'] as $part => $n) {
                $sum['daypart'][$part] += $n;
            }
            foreach ($stats['weekday'] as $day => $n) {
                $sum['weekday'][$day] += $n;
            }
        }

        return $sum;
    }

    /**
     * Shares rounded to 4 decimals that still sum to exactly 1 (largest remainder); all 0 when empty.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $counts
     * @return array<TKey, float>
     */
    private static function shares(array $counts): array
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return array_map(fn (): float => 0.0, $counts);
        }

        $units = [];
        $remainders = [];
        foreach ($counts as $key => $count) {
            $exact = $count * 10000 / $total;
            $units[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $units[$key];
        }

        arsort($remainders);
        $left = 10000 - array_sum($units);
        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $units[$key]++;
            $left--;
        }

        return array_map(fn (int $u): float => $u / 10000, $units);
    }

    /**
     * Pre-encoded so integer keys ("0".."6") and empty maps stay JSON objects.
     *
     * @param  array<array-key, float>  $map
     */
    private static function jsonObject(array $map): string
    {
        return json_encode($map === [] ? new stdClass : (object) $map, self::JSON_FLAGS);
    }
}
