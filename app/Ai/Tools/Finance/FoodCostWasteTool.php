<?php

declare(strict_types=1);

namespace App\Ai\Tools\Finance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\FoodCostWasteReport;

final class FoodCostWasteTool extends ReportTool
{
    public function name(): string
    {
        return 'food_cost_waste';
    }

    public function description(): string
    {
        return 'Review food cost, waste, inventory variance and purchase-price movements from the food cost and waste report.';
    }

    public function progressLabel(): string
    {
        return 'براجع تكلفة الخامات والهالك…';
    }

    protected function report(MetricsQuery $query): array
    {
        $report = FoodCostWasteReport::for($query);

        return [
            'overview' => $report->kpiOverview(), 'branches' => array_values($report->branchMatrix()),
            'top_wasted_items' => $report->topWastedItems(10),
            'stocktaking_variances' => $report->stocktakingVariances(10),
            'purchase_price_movements' => $report->purchasePriceMovement(10),
        ];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        $waste = $metrics['overview']->wasteCost;

        return "تكلفة الهالك {$waste} ج.م في نطاق {$branchCount} فروع؛ راجعت فروق الجرد وأسعار الشراء.";
    }
}
