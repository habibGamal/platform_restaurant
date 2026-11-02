<?php

declare(strict_types=1);

namespace App\Domain\Menu;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Computes per-item behaviour signals from the warehouse (completed orders only) and the
 * deterministic "hard" roles that need no model judgement.
 */
final class RoleSignals
{
    private const RAW_TYPES = ['raw_material', 'consumable'];

    /**
     * @return array<string, ItemSignals> keyed by item key (COALESCE(product_ref, product_uuid))
     */
    public function compute(int $organizationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $bindings = [$organizationId, $from->toDateString(), $to->toDateString()];

        $stats = DB::select(
            'SELECT '.ItemKey::SQL.' AS item_key,
                    COUNT(DISTINCT o.id) AS orders_with,
                    SUM(oi.quantity) AS qty,
                    SUM(oi.quantity * oi.price) AS revenue,
                    SUM(oi.quantity * oi.unit_cost) AS cost
             FROM wh_order_items oi
             JOIN wh_orders o ON o.branch_id = oi.branch_id AND o.uuid = oi.order_uuid
             WHERE o.organization_id = ? AND o.status = \'completed\' AND o.deleted_at IS NULL
               AND o.business_date BETWEEN ? AND ?
               AND '.ItemKey::SQL.' IS NOT NULL
             GROUP BY '.ItemKey::SQL,
            $bindings,
        );

        if ($stats === []) {
            return [];
        }

        // Orders whose only distinct item is this one.
        $soloRows = DB::select(
            'SELECT t.item_key AS item_key, COUNT(*) AS n FROM (
                SELECT o.id AS order_id, MIN('.ItemKey::SQL.') AS item_key
                FROM wh_order_items oi
                JOIN wh_orders o ON o.branch_id = oi.branch_id AND o.uuid = oi.order_uuid
                WHERE o.organization_id = ? AND o.status = \'completed\' AND o.deleted_at IS NULL
                  AND o.business_date BETWEEN ? AND ?
                  AND '.ItemKey::SQL.' IS NOT NULL
                GROUP BY o.id
                HAVING COUNT(DISTINCT '.ItemKey::SQL.') = 1
             ) t GROUP BY t.item_key',
            $bindings,
        );
        $solo = [];
        foreach ($soloRows as $row) {
            $solo[(string) $row->item_key] = (int) $row->n;
        }

        $meta = $this->catalogMeta($organizationId);

        // First pass: raw figures, so the median price of manufactured items is known.
        $items = [];
        foreach ($stats as $row) {
            $key = (string) $row->item_key;
            $qty = (float) $row->qty;
            $info = $meta[$key] ?? null;
            $items[$key] = [
                'name' => $info['name'] ?? $key,
                'category' => $info['category'] ?? null,
                'type' => $info['type'] ?? null,
                'avgPrice' => $qty > 0 ? (float) $row->revenue / $qty : 0.0,
                'avgUnitCost' => $qty > 0 ? (float) $row->cost / $qty : 0.0,
                'ordersWith' => (int) $row->orders_with,
                'qty' => $qty,
            ];
        }

        // A null product type (not mirrored from the POS) counts as manufactured for the median.
        $median = $this->median(array_values(array_map(
            fn (array $i): float => $i['avgPrice'],
            array_filter($items, fn (array $i): bool => $i['type'] === null || $i['type'] === 'manufactured'),
        )));

        $signals = [];
        foreach ($items as $key => $i) {
            $with = $i['ordersWith'];
            $only = min($solo[$key] ?? 0, $with);
            $signals[$key] = new ItemSignals(
                itemKey: $key,
                name: $i['name'],
                categoryName: $i['category'],
                productType: $i['type'],
                avgPrice: $i['avgPrice'],
                avgUnitCost: $i['avgUnitCost'],
                ordersWith: $with,
                ordersAsOnlyLine: $only,
                attachRate: $with > 0 ? 1 - $only / $with : 0.0,
                avgQtyPerOrder: $with > 0 ? $i['qty'] / $with : 0.0,
                priceVsMedian: $median > 0 ? $i['avgPrice'] / $median : 0.0,
            );
        }

        return $signals;
    }

    /**
     * Roles that follow from the data alone: stock/consumable products are Raw; a near-free,
     * zero-cost line that nearly always rides on another item is an Adjustment.
     */
    public static function hardRole(ItemSignals $s): ?ItemRole
    {
        if (in_array($s->productType, self::RAW_TYPES, true)) {
            return ItemRole::Raw;
        }

        $medianPrice = $s->priceVsMedian > 0 ? $s->avgPrice / $s->priceVsMedian : 0.0;
        $priceCeiling = max(1.0, 0.02 * $medianPrice);

        if ($s->avgPrice <= $priceCeiling && abs($s->avgUnitCost) < 0.005 && $s->attachRate >= 0.9) {
            return ItemRole::Adjustment;
        }

        return null;
    }

    /**
     * Name, category and type per item key (first product in id order wins when branches disagree).
     *
     * @return array<string, array{name: string, category: ?string, type: ?string}>
     */
    private function catalogMeta(int $organizationId): array
    {
        $rows = DB::table('dim_products as p')
            ->leftJoin('dim_categories as c', function ($join) {
                $join->on('c.branch_id', '=', 'p.branch_id')->on('c.uuid', '=', 'p.category_uuid');
            })
            ->where('p.organization_id', $organizationId)
            ->whereNull('p.deleted_at')
            ->orderBy('p.id')
            ->get(['p.uuid', 'p.product_ref', 'p.name', 'p.type', 'c.name as category_name']);

        $meta = [];
        foreach ($rows as $row) {
            $key = (string) ($row->product_ref ?? $row->uuid);
            $meta[$key] ??= [
                'name' => (string) $row->name,
                'category' => $row->category_name !== null ? (string) $row->category_name : null,
                'type' => $row->type !== null ? (string) $row->type : null,
            ];
        }

        return $meta;
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
