<?php

declare(strict_types=1);

namespace App\Domain\Menu;

use App\Domain\Metrics\MetricsQuery;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Classic menu-engineering quadrants (popularity × unit contribution margin), computed separately for
 * every marketable role over the query's period and branch scope. Items without a trusted role, new
 * items (fewer than MIN_DAYS_ON_SALE days on sale) and items with unknown cost never enter a group.
 *
 * @phpstan-type ItemRow array{key: string, name: string, role: string, qty: float, net_sales: float, unit_margin: float, margin_pct: float|null, mix_share: float, popularity_ratio: float, margin_vs_avg: float|null}
 * @phpstan-type SideRow array{key: string, name: string, role: string, qty: float, net_sales: float, days_on_sale: int}
 * @phpstan-type Quadrants array{hidden_gems: list<ItemRow>, stars: list<ItemRow>, workhorses: list<ItemRow>, weak: list<ItemRow>}
 * @phpstan-type Result array{status: 'ok'|'insufficient_data', reason?: string, groups?: array<string, Quadrants>, new_items: list<SideRow>, unknown_cost: list<SideRow>, unclassified_count: int, floors: array<string, float>}
 */
final class MenuEngineeringAnalysis
{
    public const MIN_DAYS_ON_SALE = 14;

    public const MIN_PERIOD_DAYS = 14;

    public const MIN_COMPLETED_ORDERS = 200;

    /** An item is popular when its mix share reaches this fraction of the fair share 1/N. */
    public const POPULARITY_THRESHOLD = 0.70;

    public function __construct(private readonly ItemRoles $roles) {}

    /**
     * @param  ItemRoleMap|null  $roleMap  an already-resolved role map (skips the classification attempt)
     * @return Result
     */
    public function run(MetricsQuery $query, Organization $organization, ?ItemRoleMap $roleMap = null): array
    {
        if ($query->filters->channel !== null) {
            throw new InvalidArgumentException('Menu engineering does not support filtering by channel.');
        }

        $empty = ['new_items' => [], 'unknown_cost' => [], 'unclassified_count' => 0, 'floors' => []];

        $reason = $this->insufficientDataReason($query);
        if ($reason !== null) {
            return ['status' => 'insufficient_data', 'reason' => $reason] + $empty;
        }

        $items = $this->itemTotals($query);
        $roleMap ??= $this->roles->ensureClassified($organization, array_keys($items));
        $names = $this->names($query, array_keys($items));

        /** @var array<string, list<array{key: string, name: string, role: string, qty: float, net_sales: float, cogs: float}>> $eligible */
        $eligible = [];
        $newItems = [];
        $unknownCost = [];
        $unclassified = 0;

        foreach ($items as $key => $item) {
            $role = $roleMap->roleOf($key);
            if ($role === null) {
                $unclassified++;

                continue;
            }
            if (! $role->isMarketable()) {
                continue;
            }

            $base = ['key' => $key, 'name' => $names[$key] ?? $key, 'role' => $role->value, 'qty' => $item['qty'], 'net_sales' => $item['net_sales']];

            if ($item['days'] < self::MIN_DAYS_ON_SALE) {
                $newItems[] = $base + ['days_on_sale' => $item['days']];
            }
            if ($item['cogs'] <= 0.0) {
                $unknownCost[] = $base + ['days_on_sale' => $item['days']];
            }
            if ($item['days'] >= self::MIN_DAYS_ON_SALE && $item['cogs'] > 0.0) {
                $eligible[$role->value][] = $base + ['cogs' => $item['cogs']];
            }
        }

        $groups = [];
        $floors = [];
        foreach ($eligible as $role => $rows) {
            if ($rows === []) {
                continue;
            }
            [$groups[$role], $floors[$role]] = $this->quadrants($rows);
        }

        return [
            'status' => 'ok',
            'groups' => $groups,
            'new_items' => $newItems,
            'unknown_cost' => $unknownCost,
            'unclassified_count' => $unclassified,
            'floors' => $floors,
        ];
    }

    /**
     * @param  non-empty-list<array{key: string, name: string, role: string, qty: float, net_sales: float, cogs: float}>  $rows
     * @return array{0: Quadrants, 1: float}
     */
    private function quadrants(array $rows): array
    {
        $n = count($rows);
        $totalQty = array_sum(array_column($rows, 'qty'));
        $totalNet = array_sum(array_column($rows, 'net_sales'));
        $totalMargin = $totalNet - array_sum(array_column($rows, 'cogs'));
        $avgCm = $totalMargin / $totalQty;

        $quadrants = ['hidden_gems' => [], 'stars' => [], 'workhorses' => [], 'weak' => []];

        foreach ($rows as $row) {
            $margin = $row['net_sales'] - $row['cogs'];
            $unitCm = $margin / $row['qty'];
            $mixShare = $row['qty'] / $totalQty;
            $popularityRatio = $mixShare * $n;

            $popular = $popularityRatio >= self::POPULARITY_THRESHOLD;
            $profitable = $unitCm >= $avgCm;

            $quadrant = match (true) {
                $profitable && ! $popular => 'hidden_gems',
                $profitable => 'stars',
                $popular => 'workhorses',
                default => 'weak',
            };

            $quadrants[$quadrant][] = [
                'key' => $row['key'],
                'name' => $row['name'],
                'role' => $row['role'],
                'qty' => round($row['qty'], 3),
                'net_sales' => round($row['net_sales'], 2),
                'unit_margin' => round($unitCm, 2),
                'margin_pct' => $row['net_sales'] > 0 ? round($margin / $row['net_sales'], 4) : null,
                'mix_share' => round($mixShare, 4),
                'popularity_ratio' => round($popularityRatio, 4),
                'margin_vs_avg' => $avgCm > 0 ? round($unitCm / $avgCm, 4) : null,
            ];
        }

        $byMargin = fn (array $a, array $b): int => [$b['margin_vs_avg'], $b['qty']] <=> [$a['margin_vs_avg'], $a['qty']];
        $byPopularity = fn (array $a, array $b): int => [$b['popularity_ratio'], $b['margin_vs_avg']] <=> [$a['popularity_ratio'], $a['margin_vs_avg']];
        usort($quadrants['hidden_gems'], $byMargin);
        usort($quadrants['stars'], $byMargin);
        usort($quadrants['workhorses'], $byPopularity);
        usort($quadrants['weak'], $byMargin);

        $floor = $totalNet > 0 ? $totalMargin / $totalNet : 0.0;

        return [$quadrants, $floor];
    }

    /** The Arabic reason the period cannot support the analysis, or null when it can. */
    private function insufficientDataReason(MetricsQuery $query): ?string
    {
        $row = $query->scoped('agg_branch_daily')
            ->selectRaw('COUNT(DISTINCT business_date) AS days, COALESCE(SUM(orders_count), 0) AS orders')
            ->first();

        $days = (int) ($row->days ?? 0);
        $orders = (int) ($row->orders ?? 0);

        if ($days < self::MIN_PERIOD_DAYS) {
            return "الفترة فيها {$days} يوم بيانات بس، ومحتاجين ".self::MIN_PERIOD_DAYS.' يوم على الأقل عشان تحليل المنيو يبقى موثوق.';
        }
        if ($orders < self::MIN_COMPLETED_ORDERS) {
            return "الفترة فيها {$orders} طلب مكتمل بس، ومحتاجين ".self::MIN_COMPLETED_ORDERS.' طلب على الأقل عشان تحليل المنيو يبقى موثوق.';
        }

        return null;
    }

    /**
     * Totals per item key over the period, only for items that actually sold.
     *
     * @return array<string, array{qty: float, net_sales: float, cogs: float, days: int}>
     */
    private function itemTotals(MetricsQuery $query): array
    {
        $rows = $query->scoped('agg_product_daily')
            ->selectRaw(ItemKey::SQL.' AS item_key,
                SUM(qty_sold) AS qty,
                SUM(net_sales) AS net_sales,
                SUM(cogs) AS cogs,
                COUNT(DISTINCT CASE WHEN qty_sold > 0 THEN business_date END) AS days')
            ->groupBy(DB::raw(ItemKey::SQL))
            ->get();

        $items = [];
        foreach ($rows as $row) {
            if ((float) $row->qty > 0) {
                $items[(string) $row->item_key] = [
                    'qty' => (float) $row->qty,
                    'net_sales' => (float) $row->net_sales,
                    'cogs' => (float) $row->cogs,
                    'days' => (int) $row->days,
                ];
            }
        }

        return $items;
    }

    /**
     * Display name per item key from the in-scope branches' products (first product in id order wins).
     *
     * @param  list<string>  $itemKeys
     * @return array<string, string>
     */
    private function names(MetricsQuery $query, array $itemKeys): array
    {
        if ($itemKeys === []) {
            return [];
        }

        // dim_products keeps the product uuid in `uuid`, so the item key is spelled out here.
        $keySql = 'COALESCE(product_ref, uuid)';
        $rows = DB::table('dim_products')
            ->whereIn('branch_id', $query->branchIds())
            ->whereIn(DB::raw($keySql), $itemKeys)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->selectRaw($keySql.' AS item_key, name')
            ->get();

        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row->item_key] ??= (string) $row->name;
        }

        return $names;
    }
}
