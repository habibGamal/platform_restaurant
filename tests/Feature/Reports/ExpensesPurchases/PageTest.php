<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\ExpensesPurchases;

use App\Filament\App\Pages\Reports\ExpensesPurchases;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\BudgetVsActualWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\CategoryBranchMatrixWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\ExpenseListTableWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\ExpensesPurchasesKpiStatsWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\ExpensesPurchasesNoticeWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\ExpensesVsSalesGrowthWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\PurchasesPerBranchWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\PurchasesVsCogsWidget;
use App\Filament\App\Widgets\Reports\ExpensesPurchases\SupplierSummaryTableWidget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Cairo'));
        $this->fixture = TwoBranchesTwoWeeks::create();

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_owner_can_open_page_and_render_all_widgets(): void
    {
        $this->actingAs($this->fixture->owner);

        $this->get(ExpensesPurchases::getUrl())->assertSuccessful();

        Livewire::test(ExpensesPurchases::class)->assertSuccessful();
        Livewire::test(ExpensesPurchasesNoticeWidget::class)->assertSuccessful();
        Livewire::test(ExpensesPurchasesKpiStatsWidget::class)->assertSuccessful();
        Livewire::test(CategoryBranchMatrixWidget::class)->assertSuccessful();
        Livewire::test(BudgetVsActualWidget::class)->assertSuccessful();
        Livewire::test(ExpensesVsSalesGrowthWidget::class)->assertSuccessful();
        Livewire::test(PurchasesPerBranchWidget::class)->assertSuccessful();
        Livewire::test(SupplierSummaryTableWidget::class)->assertSuccessful();
        Livewire::test(PurchasesVsCogsWidget::class)->assertSuccessful();
        Livewire::test(ExpenseListTableWidget::class)->assertSuccessful();
    }

    public function test_analyst_can_open_page_and_render_all_widgets(): void
    {
        $this->actingAs($this->fixture->analyst);

        $this->get(ExpensesPurchases::getUrl())->assertSuccessful();

        Livewire::test(ExpensesPurchases::class)->assertSuccessful();
        Livewire::test(ExpensesPurchasesNoticeWidget::class)->assertSuccessful();
        Livewire::test(ExpensesPurchasesKpiStatsWidget::class)->assertSuccessful();
        Livewire::test(CategoryBranchMatrixWidget::class)->assertSuccessful();
        Livewire::test(BudgetVsActualWidget::class)->assertSuccessful();
        Livewire::test(ExpensesVsSalesGrowthWidget::class)->assertSuccessful();
        Livewire::test(PurchasesPerBranchWidget::class)->assertSuccessful();
        Livewire::test(SupplierSummaryTableWidget::class)->assertSuccessful();
        Livewire::test(PurchasesVsCogsWidget::class)->assertSuccessful();
        Livewire::test(ExpenseListTableWidget::class)->assertSuccessful();
    }

    public function test_manager_a_widgets_never_contain_branch_b_name_or_figures(): void
    {
        $this->actingAs($this->fixture->managerA);

        $this->get(ExpensesPurchases::getUrl())->assertSuccessful();

        Livewire::test(ExpensesPurchases::class)
            ->assertSuccessful()
            ->assertDontSee('Branch B');

        // Category Matrix
        Livewire::test(CategoryBranchMatrixWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('إجمالي الفروع');

        // Budget vs Actual
        Livewire::test(BudgetVsActualWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Expenses vs Sales Growth
        Livewire::test(ExpensesVsSalesGrowthWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('4,160.00'); // Branch B sales

        // Purchases per Branch
        Livewire::test(PurchasesPerBranchWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Purchases vs COGS
        Livewire::test(PurchasesVsCogsWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('1,625.00'); // Branch B COGS

        // Expense List
        Livewire::test(ExpenseListTableWidget::class, [
            'pageFilters' => [
                'preset' => 'custom',
                'from' => '2026-10-03',
                'to' => '2026-10-16',
            ],
        ])
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');
    }

    public function test_user_without_analytics_view_permission_gets_403(): void
    {
        /** @var User $unauthorizedUser */
        $unauthorizedUser = User::factory()->create([
            'organization_id' => $this->fixture->organization->id,
        ]);

        $this->actingAs($unauthorizedUser);

        $this->get(ExpensesPurchases::getUrl())->assertForbidden();
    }
}
