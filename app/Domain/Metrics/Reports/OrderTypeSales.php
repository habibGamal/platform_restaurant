<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

final class OrderTypeSales
{
    public const LABELS = [
        'dine_in' => 'صالة', 'takeaway' => 'سفري', 'delivery' => 'توصيل',
        'drive_thru' => 'طلب السيارة', 'online' => 'أونلاين',
        'web_delivery' => 'توصيل عبر الموقع', 'web_takeaway' => 'سفري عبر الموقع',
    ];

    /** @param iterable<object> $dailyRows
     * @param  array<int, string>  $branchNames
     * @return array{totals: array<string, array{orders: int, sales: float}>, rows: array<string, array{branch: string, type: string, orders: int, sales: float}>}
     */
    public static function summarize(iterable $dailyRows, array $branchNames, ?string $channel = null): array
    {
        $totals = [];
        $rows = [];
        foreach ($dailyRows as $daily) {
            $branchId = (int) $daily->branch_id;
            if (! isset($branchNames[$branchId])) {
                continue;
            }
            $types = json_decode((string) ($daily->sales_by_type ?? '{}'), true);
            foreach (is_array($types) ? $types : [] as $type => $values) {
                if (! is_array($values) || ($channel !== null && $channel !== '' && $type !== $channel)) {
                    continue;
                }
                $key = $branchId.':'.$type;
                $totals[$type] ??= ['orders' => 0, 'sales' => 0.0];
                $rows[$key] ??= ['branch' => $branchNames[$branchId], 'type' => self::LABELS[$type] ?? (string) $type, 'orders' => 0, 'sales' => 0.0];
                $orders = (int) ($values['orders'] ?? 0);
                $sales = (float) ($values['net_sales'] ?? 0);
                $totals[$type]['orders'] += $orders;
                $totals[$type]['sales'] += $sales;
                $rows[$key]['orders'] += $orders;
                $rows[$key]['sales'] += $sales;
            }
        }

        return compact('totals', 'rows');
    }
}
