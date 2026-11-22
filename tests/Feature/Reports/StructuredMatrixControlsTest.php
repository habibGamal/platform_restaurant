<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\CategoryBranchMatrixWidget;
use App\Filament\App\Widgets\Reports\Profitability\ProfitabilityPlMatrixWidget;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class StructuredMatrixControlsTest extends TestCase
{
    public function test_structured_matrices_have_no_search_or_sort_controls(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        foreach ([ProfitabilityPlMatrixWidget::class, CategoryBranchMatrixWidget::class] as $widget) {
            $table = Livewire::test($widget)->instance()->getTable();

            $this->assertFalse($table->isSearchable(), $widget);
            foreach ($table->getColumns() as $column) {
                $this->assertFalse($column->isSortable(), $widget.': '.$column->getName());
                $this->assertFalse($column->isSearchable(), $widget.': '.$column->getName());
            }
        }
    }

    public function test_comparison_tables_keep_search_and_sort_controls(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $table = Livewire::test(SearchableComparisonFixture::class)->instance()->getTable();

        $this->assertTrue($table->isSearchable());
        $this->assertTrue($table->getColumn('branch')->isSortable());
    }
}

class SearchableComparisonFixture extends BaseReportViewWidget
{
    protected static bool $isLazy = false;

    protected function getColumns(): array
    {
        return ['branch' => 'Branch'];
    }

    protected function getRows(): array
    {
        return ['a' => ['branch' => 'A']];
    }
}
