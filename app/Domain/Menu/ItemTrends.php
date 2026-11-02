<?php

declare(strict_types=1);

namespace App\Domain\Menu;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ItemTrends
{
    /** @return array{status: string, reason?: string, rising: list<array<string, mixed>>, falling: list<array<string, mixed>>} */
    public function run(MetricsQuery $query, ItemRoleMap $roles): array
    {
        if ($query->filters->channel !== null) {
            throw new InvalidArgumentException('Item trends do not support filtering by channel.');
        }
        $to = $query->filters->period->to;
        $current = new Period($to->subDays(27), $to);
        $previous = $current->shift(-28);
        $history = new Period($previous->from, $current->to);
        $coverage = $query->scoped('agg_branch_daily', $history)->selectRaw('COUNT(DISTINCT business_date) AS days, SUM(orders_count) AS orders')->first();
        if ((int) ($coverage->days ?? 0) < 56 || (int) ($coverage->orders ?? 0) < 200) {
            return ['status' => 'insufficient_data', 'reason' => 'محتاجين 56 يوم بيانات و200 طلب على الأقل عشان نقارن اتجاهات الأصناف.', 'rising' => [], 'falling' => []];
        }
        $before = $this->quantities($query, $previous);
        $after = $this->quantities($query, $current);
        $names = $this->names($query, array_keys($before));
        $rising = [];
        $falling = [];
        foreach ($before as $key => $qty) {
            $role = $roles->roleOf($key);
            if ($qty < 20 || ! $role?->isMarketable()) {
                continue;
            }
            $currentQty = $after[$key] ?? 0.0;
            $change = ($currentQty - $qty) / $qty;
            $item = ['key' => $key, 'name' => $names[$key] ?? $key, 'role' => $role->value, 'previous_qty' => $qty, 'current_qty' => $currentQty, 'change_pct' => $change];
            if ($change > 0) {
                $rising[] = $item;
            }
            if ($change < 0) {
                $falling[] = $item;
            }
        }
        usort($rising, fn (array $a, array $b): int => [$b['change_pct'], $b['current_qty'], $a['key']] <=> [$a['change_pct'], $a['current_qty'], $b['key']]);
        usort($falling, fn (array $a, array $b): int => [$a['change_pct'], $b['previous_qty'], $a['key']] <=> [$b['change_pct'], $a['previous_qty'], $b['key']]);

        return ['status' => 'ok', 'rising' => array_slice($rising, 0, 5), 'falling' => array_slice($falling, 0, 5)];
    }

    /** @return array<string, float> */
    private function quantities(MetricsQuery $query, Period $period): array
    {
        $rows = $query->scoped('agg_product_daily', $period)->selectRaw(ItemKey::SQL.' AS item_key, SUM(qty_sold) AS qty')->groupBy(DB::raw(ItemKey::SQL))->get();
        $quantities = [];
        foreach ($rows as $row) {
            if ($row->item_key !== null) {
                $quantities[(string) $row->item_key] = (float) $row->qty;
            }
        }

        return $quantities;
    }

    /** @param list<string> $keys
     * @return array<string, string>
     */
    private function names(MetricsQuery $query, array $keys): array
    {
        $rows = DB::table('dim_products')->where('organization_id', $query->user->organization_id)->whereIn('branch_id', $query->branchIds())
            ->whereNull('deleted_at')->whereIn(DB::raw('COALESCE(product_ref, uuid)'), $keys)->orderBy('id')->selectRaw('COALESCE(product_ref, uuid) AS item_key, name')->get();
        $names = [];
        foreach ($rows as $row) {
            $names[(string) $row->item_key] ??= (string) $row->name;
        }

        return $names;
    }
}
