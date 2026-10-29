<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports;

use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use Filament\Widgets\Widget;

abstract class BaseReportViewWidget extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'filament.app.widgets.reports.base-report-view-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    protected ?string $heading = null;

    public function getHeading(): ?string
    {
        return $this->heading;
    }

    /**
     * @return array<string, string> Key => Label
     */
    abstract protected function getColumns(): array;

    /**
     * @return array<int|string, array<string, mixed>>
     */
    abstract protected function getRows(): array;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'heading' => $this->getHeading(),
            'columns' => $this->getColumns(),
            'rows' => $this->getRows(),
        ];
    }
}
