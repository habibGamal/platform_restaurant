<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\FoodCostWaste;

use App\Filament\App\Pages\Reports\FoodCostWaste;
use App\Filament\App\Widgets\Reports\FoodCostWaste\FoodCostBranchMatrixWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\FoodCostKpiOverviewWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\FoodCostTrendChartWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\PurchasePriceMovementTableWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\StocktakingVarianceTableWidget;
use App\Filament\App\Widgets\Reports\FoodCostWaste\TopWastedItemsTableWidget;
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

    public function test_owner_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->owner);

        $this->get(FoodCostWaste::getUrl())->assertSuccessful();

        Livewire::test(FoodCostWaste::class)->assertSuccessful();
        Livewire::test(FoodCostKpiOverviewWidget::class)->assertSuccessful();
        Livewire::test(FoodCostBranchMatrixWidget::class)->assertSuccessful();
        Livewire::test(FoodCostTrendChartWidget::class)->assertSuccessful();
        Livewire::test(TopWastedItemsTableWidget::class)->assertSuccessful();
        Livewire::test(StocktakingVarianceTableWidget::class)->assertSuccessful();
        Livewire::test(PurchasePriceMovementTableWidget::class)->assertSuccessful();
    }

    public function test_analyst_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->analyst);

        $this->get(FoodCostWaste::getUrl())->assertSuccessful();

        Livewire::test(FoodCostWaste::class)->assertSuccessful();
        Livewire::test(FoodCostKpiOverviewWidget::class)->assertSuccessful();
        Livewire::test(FoodCostBranchMatrixWidget::class)->assertSuccessful();
        Livewire::test(FoodCostTrendChartWidget::class)->assertSuccessful();
        Livewire::test(TopWastedItemsTableWidget::class)->assertSuccessful();
        Livewire::test(StocktakingVarianceTableWidget::class)->assertSuccessful();
        Livewire::test(PurchasePriceMovementTableWidget::class)->assertSuccessful();
    }

    public function test_manager_a_widgets_never_contain_branch_b_name_or_figures(): void
    {
        $this->actingAs($this->fixture->managerA);

        $this->get(FoodCostWaste::getUrl())->assertSuccessful();

        $branchBName = $this->fixture->branchB->name;

        // Page render
        Livewire::test(FoodCostWaste::class)
            ->assertSuccessful()
            ->assertDontSee($branchBName);

        // Branch matrix widget
        Livewire::test(FoodCostBranchMatrixWidget::class)
            ->assertSuccessful()
            ->assertSee($this->fixture->branchA->name)
            ->assertDontSee($branchBName)
            ->assertDontSee('4,160'); // Branch B net sales

        // Wasted items widget
        Livewire::test(TopWastedItemsTableWidget::class)
            ->assertSuccessful()
            ->assertDontSee($branchBName)
            ->assertDontSee('Cola'); // Branch B wasted Cola, manager A must not see it

        // Stocktaking variance widget
        Livewire::test(StocktakingVarianceTableWidget::class)
            ->assertSuccessful()
            ->assertDontSee($branchBName);

        // Purchase price movement widget
        Livewire::test(PurchasePriceMovementTableWidget::class)
            ->assertSuccessful()
            ->assertDontSee($branchBName);

        // Trend chart widget
        Livewire::test(FoodCostTrendChartWidget::class)
            ->assertSuccessful()
            ->assertDontSee($branchBName);
    }

    public function test_user_without_analytics_view_permission_gets_403(): void
    {
        $unauthorizedUser = User::factory()->forOrganization($this->fixture->organization)->create();

        $this->actingAs($unauthorizedUser);

        $this->get(FoodCostWaste::getUrl())->assertForbidden();
    }
}
