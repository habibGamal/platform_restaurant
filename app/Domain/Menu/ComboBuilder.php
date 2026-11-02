<?php

declare(strict_types=1);

namespace App\Domain\Menu;

use App\Domain\Metrics\MetricsQuery;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ComboBuilder
{
    public function __construct(
        private readonly MenuEngineeringAnalysis $engineering,
        private readonly BasketAnalysis $baskets,
        private readonly ItemRoles $roles,
    ) {}

    /** @return array{status: string, reason?: string, margin_floor_pct: float, combos: list<array<string, mixed>>} */
    public function proposeFor(MetricsQuery $query, Organization $org, ?string $targetKey = null, int $max = 3): array
    {
        if ($query->user->organization_id !== $org->id) {
            throw new AuthorizationException;
        }
        $analysis = $this->engineering->run($query, $org);
        $floor = $analysis['floors']['main'] ?? 0.0;
        $empty = ['margin_floor_pct' => $floor, 'combos' => []];
        if ($analysis['status'] !== 'ok') {
            return ['status' => 'insufficient_data', 'reason' => $analysis['reason'] ?? 'البيانات غير كافية.'] + $empty;
        }
        $groups = $analysis['groups']['main'] ?? [];
        $eligible = array_merge(...array_values($groups ?: ['empty' => []]));
        $limit = min(3, $max);
        if ($limit <= 0) {
            return ['status' => 'ok'] + $empty;
        }

        // An explicit target gets up to $limit combos of its own. Without one, walk the hidden gems in rank
        // order and take each one's best companion, so a gem that is never ordered with a side does not
        // leave the whole answer without a combo.
        if ($targetKey !== null) {
            if (! in_array($targetKey, array_column($eligible, 'key'), true)) {
                return ['status' => 'ok'] + $empty;
            }
            $targets = [$targetKey];
            $perTarget = $limit;
        } else {
            $targets = array_column($groups['hidden_gems'] ?? [], 'key');
            $perTarget = 1;
        }

        $roles = $this->roles->mapFor($org->id);
        $totals = $this->prices($query);
        $combos = [];
        foreach ($targets as $target) {
            $target = (string) $target;
            $main = $totals[$target] ?? null;
            if ($roles->roleOf($target) !== ItemRole::Main || $main === null) {
                continue;
            }
            // Do not cap pairs before excluding drinks: the global marketing rule excludes them from combos.
            $pairs = $this->baskets->pairsFor($query, $roles, $target, PHP_INT_MAX);
            if ($pairs['status'] !== 'ok') {
                return ['status' => 'insufficient_data', 'reason' => $pairs['reason'] ?? 'البيانات غير كافية.'] + $empty;
            }
            $found = 0;
            foreach ($pairs['pairs'] as $pair) {
                $partnerKey = (string) $pair['partner_key'];
                $role = $roles->roleOf($partnerKey);
                $partner = $totals[$partnerKey] ?? null;
                if (! in_array($role, [ItemRole::Side, ItemRole::Dessert], true) || $partner === null) {
                    continue;
                }
                $pricing = $this->price($main['price'] + $partner['price'], $main['cost'] + $partner['cost'], $floor);
                if ($pricing === null) {
                    continue;
                }
                $combos[] = [
                    'items' => [
                        ['key' => $target, 'name' => $pair['anchor_name'], 'role' => 'main', 'list_price' => $main['price']],
                        ['key' => $partnerKey, 'name' => $pair['partner_name'], 'role' => $role->value, 'list_price' => $partner['price']],
                    ],
                    ...$pricing,
                    'why' => 'الأصناف دي اتطلبت مع بعض في '.$pair['together_orders'].' طلب، وقوة الارتباط '.round((float) $pair['lift'], 2).'، والسعر بيحافظ على هامش الربح المطلوب.',
                ];
                if (++$found >= $perTarget || count($combos) >= $limit) {
                    break;
                }
            }
            if (count($combos) >= $limit) {
                break;
            }
        }

        return ['status' => 'ok', 'margin_floor_pct' => $floor, 'combos' => $combos];
    }

    /** @return array<string, array{price: float, cost: float}> */
    private function prices(MetricsQuery $query): array
    {
        $rows = $query->scoped('agg_product_daily')->selectRaw(ItemKey::SQL.' AS item_key, SUM(qty_sold) AS qty, SUM(net_sales) AS net, SUM(cogs) AS cost')
            ->groupBy(DB::raw(ItemKey::SQL))->get();
        $prices = [];
        foreach ($rows as $row) {
            if ((float) $row->qty > 0 && (float) $row->net > 0 && (float) $row->cost > 0) {
                $prices[(string) $row->item_key] = ['price' => (float) $row->net / (float) $row->qty, 'cost' => (float) $row->cost / (float) $row->qty];
            }
        }

        return $prices;
    }

    /** @return array{list_price: float, suggested_price: float, cost: float, margin_pct: float, discount_pct: float}|null */
    private function price(float $listPrice, float $cost, float $floor): ?array
    {
        foreach ([0.15, 0.12, 0.10, 0.08, 0.05] as $discount) {
            $price = $listPrice * (1 - $discount);
            if (($price - $cost) / $price < $floor) {
                continue;
            }
            $price = floor($price / 5) * 5;
            if ($price <= $cost || ($price - $cost) / $price < $floor) {
                continue;
            }

            return ['list_price' => $listPrice, 'suggested_price' => $price, 'cost' => $cost, 'margin_pct' => ($price - $cost) / $price, 'discount_pct' => 1 - $price / $listPrice];
        }

        return null;
    }
}
