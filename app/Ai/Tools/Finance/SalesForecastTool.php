<?php

declare(strict_types=1);

namespace App\Ai\Tools\Finance;

use App\Domain\Forecast\SalesForecast;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use Carbon\CarbonImmutable;

final class SalesForecastTool extends ReportTool
{
    public function name(): string
    {
        return 'sales_forecast';
    }

    public function description(): string
    {
        return 'Forecast net sales after returns for the requested target dates using up to 56 prior complete days, weekday medians and a clamped trend. Returns a low/expected/high estimate, not a guarantee. Set explicit custom target dates. Channel and like-for-like filters are unsupported.';
    }

    public function progressLabel(): string
    {
        return 'بحسب توقع المبيعات من الأيام المكتملة…';
    }

    protected function unsupportedFilterReason(MetricsQuery $query): ?string
    {
        return parent::unsupportedFilterReason($query)
            ?? ($query->filters()->likeForLike ? 'توقع المبيعات لا يدعم تصفية الفروع المتماثلة؛ اختار الفروع مباشرة.' : null);
    }

    protected function report(MetricsQuery $query): array
    {
        $period = $query->filters()->period;

        return app(SalesForecast::class)->forecast($query, $period->from, $period->to);
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        if ($metrics['status'] !== 'ok') {
            return (string) $metrics['reason'];
        }

        return "المبيعات المتوقعة {$metrics['expected']} ج.م من {$metrics['complete_days_used']} يوم مكتمل؛ النطاق {$metrics['low']}–{$metrics['high']} ج.م.";
    }

    protected function completenessPeriod(MetricsQuery $query, array $metrics): Period
    {
        // Completeness describes historical evidence, never the unobserved future target.
        $end = CarbonImmutable::parse($metrics['history_cutoff']);
        if (is_string($metrics['history_from'])) {
            return new Period(CarbonImmutable::parse($metrics['history_from']), $end);
        }

        return new Period($end->subDays(55), $end);
    }
}
