<?php

declare(strict_types=1);

namespace App\Domain\Menu;

use App\Domain\Metrics\MetricsQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class BasketAnalysis
{
    /** Seconds one basket pass is reused (as long as the KPI tool cache): one run asks for several anchors. */
    private const CACHE_SECONDS = 300;

    /** @return array{status: string, reason?: string, orders_considered: int, pairs: list<array<string, mixed>>} */
    public function pairsFor(MetricsQuery $query, ItemRoleMap $roles, ?string $anchorKey = null, int $limit = 10): array
    {
        // The order self-join is the slow part and its pair threshold does not depend on the anchor, so pairs
        // for every main are computed once per scope and role map, then filtered. Lift order is kept.
        $all = Cache::remember($this->cacheKey($query, $roles), self::CACHE_SECONDS, fn (): array => $this->allPairs($query, $roles));
        if ($all['status'] !== 'ok') {
            return $all;
        }
        $pairs = $anchorKey === null
            ? $all['pairs']
            : array_values(array_filter($all['pairs'], fn (array $pair): bool => $pair['anchor_key'] === $anchorKey));

        return ['status' => 'ok', 'orders_considered' => $all['orders_considered'], 'pairs' => $limit > 0 ? array_slice($pairs, 0, $limit) : []];
    }

    private function cacheKey(MetricsQuery $query, ItemRoleMap $roles): string
    {
        $branchIds = $query->branchIds();
        sort($branchIds);

        return 'menu:baskets:'.md5((string) json_encode([
            $query->user->organization_id,
            $branchIds,
            $query->filters->period->from->toDateString(),
            $query->filters->period->to->toDateString(),
            $query->filters->channel,
            $roles->fingerprint(),
        ]));
    }

    /** @return array{status: string, reason?: string, orders_considered: int, pairs: list<array<string, mixed>>} */
    private function allPairs(MetricsQuery $query, ItemRoleMap $roles): array
    {
        $multi = $this->multiLineOrders($query);
        $orders = DB::query()->fromSub(clone $multi, 'multi')->count();
        if ($orders < 200) {
            return ['status' => 'insufficient_data', 'reason' => "الفترة فيها {$orders} طلب بأكثر من صنف، ومحتاجين 200 طلب على الأقل.", 'orders_considered' => $orders, 'pairs' => []];
        }

        $occurrences = $this->occurrences($query, $multi);
        $counts = DB::query()->fromSub(clone $occurrences, 'items')
            ->selectRaw('item_key, COUNT(*) AS n')->groupBy('item_key')->pluck('n', 'item_key')->all();
        $anchors = [];
        $partners = [];
        foreach ($counts as $key => $count) {
            $key = (string) $key;
            $role = $roles->roleOf($key);
            if ($role === ItemRole::Main) {
                $anchors[] = $key;
            }
            if ($role?->isComboCompanion()) {
                $partners[] = $key;
            }
        }
        if ($anchors === [] || $partners === []) {
            return ['status' => 'ok', 'orders_considered' => $orders, 'pairs' => []];
        }

        $rows = DB::query()->fromSub(clone $occurrences, 'a')
            ->joinSub(clone $occurrences, 'b', 'a.order_id', '=', 'b.order_id')
            ->whereIn('a.item_key', $anchors)->whereIn('b.item_key', $partners)
            ->selectRaw('a.item_key AS anchor_key, b.item_key AS partner_key, COUNT(*) AS together_orders')
            ->groupBy('a.item_key', 'b.item_key')
            ->havingRaw('COUNT(*) >= ?', [max(5, (int) ceil(0.002 * $orders))])
            ->get();
        $names = $this->names($query, array_merge($anchors, $partners));
        $pairs = [];
        foreach ($rows as $row) {
            $a = (string) $row->anchor_key;
            $b = (string) $row->partner_key;
            $together = (int) $row->together_orders;
            $anchorCount = (int) $counts[$a];
            $partnerCount = (int) $counts[$b];
            $pairs[] = [
                'anchor_key' => $a, 'anchor_name' => $names[$a] ?? $a, 'anchor_role' => 'main',
                'partner_key' => $b, 'partner_name' => $names[$b] ?? $b, 'partner_role' => $roles->roleOf($b)?->value,
                'together_orders' => $together,
                'support' => $together / $orders,
                'confidence' => $together / $anchorCount,
                'lift' => ($together / $anchorCount) / ($partnerCount / $orders),
            ];
        }
        usort($pairs, fn (array $a, array $b): int => [$b['lift'], $b['together_orders']] <=> [$a['lift'], $a['together_orders']]);

        return ['status' => 'ok', 'orders_considered' => $orders, 'pairs' => $pairs];
    }

    private function scopedOrders(MetricsQuery $query): Builder
    {
        $orders = $query->scoped('wh_orders')->where('organization_id', $query->user->organization_id)
            ->where('status', 'completed')->whereNull('deleted_at');

        if ($query->filters->channel !== null) {
            $orders->where('type', $query->filters->channel);
        }

        return $orders;
    }

    private function multiLineOrders(MetricsQuery $query): Builder
    {
        return DB::query()->fromSub($this->scopedOrders($query), 'o')
            ->join('wh_order_items as i', fn (JoinClause $join) => $join->on('i.organization_id', '=', 'o.organization_id')->on('i.branch_id', '=', 'o.branch_id')->on('i.order_uuid', '=', 'o.uuid'))
            ->select('o.id')->groupBy('o.id')->havingRaw('COUNT(i.id) >= 2');
    }

    private function occurrences(MetricsQuery $query, Builder $multi): Builder
    {
        return DB::query()->fromSub($this->scopedOrders($query), 'o')
            ->joinSub($multi, 'multi', 'multi.id', '=', 'o.id')
            ->join('wh_order_items as i', fn (JoinClause $join) => $join->on('i.organization_id', '=', 'o.organization_id')->on('i.branch_id', '=', 'o.branch_id')->on('i.order_uuid', '=', 'o.uuid'))
            ->whereRaw('COALESCE(i.product_ref, i.product_uuid) IS NOT NULL')
            ->selectRaw('DISTINCT o.id AS order_id, COALESCE(i.product_ref, i.product_uuid) AS item_key');
    }

    /** @param list<string> $keys
     * @return array<string, string>
     */
    private function names(MetricsQuery $query, array $keys): array
    {
        $rows = DB::table('dim_products')->where('organization_id', $query->user->organization_id)
            ->whereIn('branch_id', $query->branchIds())->whereNull('deleted_at')
            ->whereIn(DB::raw('COALESCE(product_ref, uuid)'), $keys)
            ->orderBy('id')->selectRaw('COALESCE(product_ref, uuid) AS item_key, name')->get();
        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row->item_key] ??= (string) $row->name;
        }

        return $names;
    }
}
