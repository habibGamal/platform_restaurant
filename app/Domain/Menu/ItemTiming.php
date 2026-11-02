<?php

declare(strict_types=1);

namespace App\Domain\Menu;

use App\Domain\Metrics\MetricsQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class ItemTiming
{
    public function __construct(private readonly ItemRoles $roles) {}

    /** @return array{status: string, reason?: string, weekday: list<array{weekday: int, qty_share: float}>, hour: list<array{hour: int, qty_share: float}>, slow_slots: list<array{weekday: int, hour: int}>} */
    public function run(MetricsQuery $query, string $itemKey): array
    {
        $empty = ['weekday' => [], 'hour' => [], 'slow_slots' => []];
        $orders = $this->orders($query);
        $coverage = (clone $orders)->selectRaw('COUNT(DISTINCT business_date) AS days, COUNT(*) AS orders')->first();
        if ((int) ($coverage->days ?? 0) < 14 || (int) ($coverage->orders ?? 0) < 200) {
            return ['status' => 'insufficient_data', 'reason' => 'محتاجين 14 يوم بيانات و200 طلب مكتمل على الأقل عشان نحدد توقيت تسويق الصنف.'] + $empty;
        }
        $org = $query->user->organization;
        $exists = DB::query()->fromSub(clone $orders, 'o')->join('wh_order_items as i', $this->joinItems(...))
            ->whereRaw('COALESCE(i.product_ref, i.product_uuid) = ?', [$itemKey])->exists();
        if (! $exists || $org === null || ! $this->roles->ensureClassified($org, [$itemKey])->roleOf($itemKey)?->isMarketable()) {
            return ['status' => 'insufficient_data', 'reason' => 'الصنف مش موجود في النطاق المحدد أو ملوش دور تسويقي موثوق.'] + $empty;
        }
        $rows = DB::query()->fromSub(clone $orders, 'o')->join('wh_order_items as i', $this->joinItems(...))
            ->whereRaw('COALESCE(i.product_ref, i.product_uuid) = ?', [$itemKey])->where('i.quantity', '>', 0)
            ->groupBy('o.weekday', 'o.hour_of_day')->selectRaw('o.weekday, o.hour_of_day, SUM(i.quantity) AS qty')->get();
        $total = (float) $rows->sum('qty');
        if ($total <= 0) {
            return ['status' => 'insufficient_data', 'reason' => 'مفيش كميات مباعة كفاية للصنف في الفترة دي.'] + $empty;
        }
        $weekdays = array_fill(0, 7, 0.0);
        $hours = [];
        $quantities = [];
        foreach ($rows as $row) {
            $day = (int) $row->weekday;
            $hour = (int) $row->hour_of_day;
            $weekdays[$day] += (float) $row->qty;
            $hours[$hour] = ($hours[$hour] ?? 0.0) + (float) $row->qty;
            $quantities[$day.':'.$hour] = (float) $row->qty;
        }
        // No opening-hours schedule exists: observed completed-order slots are the evidence of opening.
        $openSlots = (clone $orders)->whereNotNull('weekday')->whereNotNull('hour_of_day')->select('weekday', 'hour_of_day')->distinct()->get();
        $slots = [];
        foreach ($openSlots as $slot) {
            $day = (int) $slot->weekday;
            $hour = (int) $slot->hour_of_day;
            $slots[] = ['weekday' => $day, 'hour' => $hour, 'qty' => $quantities[$day.':'.$hour] ?? 0.0];
            $hours[$hour] ??= 0.0;
        }
        usort($slots, fn (array $a, array $b): int => [$a['qty'], $a['weekday'], $a['hour']] <=> [$b['qty'], $b['weekday'], $b['hour']]);
        ksort($hours);
        $weekdayProfile = [];
        $hourProfile = [];
        foreach ($weekdays as $day => $qty) {
            $weekdayProfile[] = ['weekday' => $day, 'qty_share' => $qty / $total];
        }
        foreach ($hours as $hour => $qty) {
            $hourProfile[] = ['hour' => $hour, 'qty_share' => $qty / $total];
        }

        return ['status' => 'ok', 'weekday' => $weekdayProfile, 'hour' => $hourProfile, 'slow_slots' => array_map(fn (array $slot): array => ['weekday' => $slot['weekday'], 'hour' => $slot['hour']], array_slice($slots, 0, 3))];
    }

    private function joinItems(JoinClause $join): void
    {
        $join->on('i.organization_id', '=', 'o.organization_id')->on('i.branch_id', '=', 'o.branch_id')->on('i.order_uuid', '=', 'o.uuid');
    }

    private function orders(MetricsQuery $query): Builder
    {
        return $query->scoped('wh_orders')->where('organization_id', $query->user->organization_id)->where('status', 'completed')->whereNull('deleted_at')
            ->whereBetween('weekday', [0, 6])->whereBetween('hour_of_day', [0, 23])
            ->when($query->filters->channel !== null, fn (Builder $orders): Builder => $orders->where('type', $query->filters->channel));
    }
}
