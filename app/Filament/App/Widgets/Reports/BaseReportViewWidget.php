<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports;

use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Widgets\Widget;

abstract class BaseReportViewWidget extends Widget implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithReportFilters, InteractsWithSchemas {
        InteractsWithSchemas::__get insteadof InteractsWithReportFilters;
    }
    use InteractsWithTable;

    protected string $view = 'filament.app.widgets.reports.base-report-view-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    protected ?string $heading = null;

    protected bool $hasTableSearchAndSorting = true;

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
        if ($this->view === 'filament.app.widgets.reports.base-report-view-widget') {
            return [];
        }

        return [
            'heading' => $this->getHeading(),
            'columns' => $this->getColumns(),
            'rows' => $this->getRows(),
        ];
    }

    public function table(Table $table): Table
    {
        $columns = $this->getTableColumnsForRecords();
        foreach ($columns as $column) {
            if ($column instanceof TextColumn) {
                $column->sortable($this->hasTableSearchAndSorting)
                    ->searchable($this->hasTableSearchAndSorting)
                    ->toggleable();
            }
        }

        return $table
            ->heading($this->getHeading())
            ->description($this->hasTableSearchAndSorting ? 'استخدم البحث والترتيب لاختيار السجلات والأعمدة التي تريد عرضها.' : null)
            ->records(fn (array $columnSearches, ?string $search, ?string $sortColumn, ?string $sortDirection, int|string $page, int|string|null $recordsPerPage) => ArrayTableRecords::paginate($this->getRows(), $columnSearches, $search, $sortColumn, $sortDirection, (int) $page, (int) ($recordsPerPage ?: 15)))
            ->columns($columns)
            ->paginated([15, 30, 50, 100])
            ->emptyStateHeading('لا توجد بيانات متاحة')
            ->emptyStateDescription(null);
    }

    /**
     * @return array<Column>
     */
    protected function getTableColumnsForRecords(): array
    {
        $columns = [];

        foreach ($this->getColumns() as $key => $label) {
            $columns[] = TextColumn::make($key)
                ->label($label)
                ->html()
                ->sortable()
                // ->searchable(isIndividual: true)
                ->toggleable()
                ->placeholder('—')
                ->extraHeaderAttributes(['class' => 'whitespace-nowrap'])
                ->extraCellAttributes(['class' => 'whitespace-nowrap']);
        }

        return $columns;
    }
}
