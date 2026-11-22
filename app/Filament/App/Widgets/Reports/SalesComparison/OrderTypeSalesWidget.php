<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\OrderTypeSales;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\Branch;
use App\Support\Format;
use Filament\Tables\Table;

class OrderTypeSalesWidget extends BaseReportViewWidget
{
    protected string $view = 'filament.app.reports.sales-comparison.order-types';

    protected ?string $heading = 'مبيعات أنواع الطلبات حسب الفرع';

    private ?array $summary = null;

    public function table(Table $table): Table
    {
        return parent::table($table)->description('مبيعات الطلبات المكتملة بعد الخصومات وقبل المرتجعات، وفق نوع الطلب المسجل في نقطة البيع.');
    }

    protected function getColumns(): array
    {
        return ['branch' => 'الفرع', 'type' => 'نوع الطلب', 'orders' => 'عدد الطلبات', 'sales' => 'المبيعات قبل المرتجعات'];
    }

    protected function getRows(): array
    {
        return array_map(fn (array $row): array => [
            ...$row,
            'branch' => e($row['branch']),
            'type' => e($row['type']),
            'sales' => Format::money($row['sales'], 2),
        ], $this->summary()['rows']);
    }

    protected function getViewData(): array
    {
        $cards = [];
        foreach ($this->summary()['totals'] as $type => $total) {
            $cards[] = ['label' => OrderTypeSales::LABELS[$type] ?? $type, 'sales' => Format::money($total['sales'], 2), 'orders' => Format::number((float) $total['orders'], 0)];
        }

        return ['cards' => $cards];
    }

    private function summary(): array
    {
        if ($this->summary !== null) {
            return $this->summary;
        }
        $user = auth()->user();
        if (! $user) {
            return $this->summary = ['totals' => [], 'rows' => []];
        }
        $query = MetricsQuery::for($user, $this->reportFilters());
        $branches = Branch::withoutGlobalScopes()->whereIn('id', $query->branchIds())->pluck('name', 'id')->all();

        return $this->summary = OrderTypeSales::summarize($query->scoped('agg_branch_daily')->get(['branch_id', 'sales_by_type']), $branches, $query->filters()->channel);
    }
}
