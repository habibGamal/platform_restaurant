<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports;

use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use Closure;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

abstract class BaseReportTableWidget extends TableWidget
{
    use InteractsWithReportFilters;

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        $records = $this->getReportRecords();

        if ($records !== null) {
            $table->records($records);
        }

        return $table->columns($this->getReportColumns());
    }

    /**
     * Return a Closure returning an array or collection of records, keyed by ID.
     */
    protected function getReportRecords(): ?Closure
    {
        return null;
    }

    /**
     * @return array<Column>
     */
    protected function getReportColumns(): array
    {
        return [];
    }
}
