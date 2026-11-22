<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports;

use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use Closure;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Pagination\Paginator;

abstract class BaseReportTableWidget extends TableWidget
{
    use InteractsWithReportFilters;

    protected static bool $isLazy = true;

    public function table(Table $table): Table
    {
        $records = $this->getReportRecords();

        if ($records !== null) {
            $table->records(function (array $columnSearches, ?string $search, ?string $sortColumn, ?string $sortDirection, int|string $page, int|string|null $recordsPerPage) use ($table, $records) {
                $rows = $table->evaluate($records, compact('columnSearches', 'search', 'sortColumn', 'sortDirection', 'page', 'recordsPerPage'));

                if ($rows instanceof Paginator) {
                    return $rows;
                }

                return ArrayTableRecords::paginate($rows, $columnSearches, $search, $sortColumn, $sortDirection, (int) $page, (int) ($recordsPerPage ?: 15));
            });
        }

        $columns = $this->getReportColumns();
        foreach ($columns as $column) {
            if ($column instanceof TextColumn) {
                $column->sortable()->searchable()->toggleable();
            }
        }

        return $table->columns($columns)
            ->description('استخدم البحث والترتيب لاختيار السجلات والأعمدة التي تريد عرضها.')
            ->paginated([15, 30, 50, 100]);
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
