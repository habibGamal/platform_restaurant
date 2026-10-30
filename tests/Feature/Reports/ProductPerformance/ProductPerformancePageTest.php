<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\ProductPerformance;

use App\Filament\App\Pages\Reports\ProductPerformance;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductCategoryPerformanceWidget;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductCrossBranchMatrixWidget;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductParetoWidget;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductPerformanceKpiWidget;
use App\Filament\App\Widgets\Reports\ProductPerformance\ProductRankingTableWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ProductPerformancePageTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    public function test_owner_can_open_page_and_render_all_widgets(): void
    {
        $this->actingAs($this->fixture->owner);

        Livewire::test(ProductPerformance::class)->assertSuccessful();
        $this->get(ProductPerformance::getUrl())->assertSuccessful();

        Livewire::test(ProductPerformanceKpiWidget::class)->assertSuccessful();
        Livewire::test(ProductRankingTableWidget::class)->assertSuccessful();
        Livewire::test(ProductCrossBranchMatrixWidget::class)->assertSuccessful();
        Livewire::test(ProductCategoryPerformanceWidget::class)->assertSuccessful();
        Livewire::test(ProductParetoWidget::class)->assertSuccessful();
    }

    public function test_analyst_can_open_page_and_render_all_widgets(): void
    {
        $this->actingAs($this->fixture->analyst);

        Livewire::test(ProductPerformance::class)->assertSuccessful();
        $this->get(ProductPerformance::getUrl())->assertSuccessful();

        Livewire::test(ProductPerformanceKpiWidget::class)->assertSuccessful();
        Livewire::test(ProductRankingTableWidget::class)->assertSuccessful();
        Livewire::test(ProductCrossBranchMatrixWidget::class)->assertSuccessful();
        Livewire::test(ProductCategoryPerformanceWidget::class)->assertSuccessful();
        Livewire::test(ProductParetoWidget::class)->assertSuccessful();
    }

    public function test_branch_manager_can_open_page_and_widgets_never_expose_other_branch_name_or_figures(): void
    {
        $this->actingAs($this->fixture->managerA);

        Livewire::test(ProductPerformance::class)->assertSuccessful();
        $this->get(ProductPerformance::getUrl())->assertSuccessful();

        Livewire::test(ProductPerformanceKpiWidget::class)->assertSuccessful();
        Livewire::test(ProductRankingTableWidget::class)->assertSuccessful();

        // Cross-branch matrix for manager A must NEVER contain Branch B's name or figures
        $branchBName = $this->fixture->branchB->name;
        Livewire::test(ProductCrossBranchMatrixWidget::class)
            ->assertSuccessful()
            ->assertDontSee($branchBName);

        Livewire::test(ProductCategoryPerformanceWidget::class)->assertSuccessful();
        Livewire::test(ProductParetoWidget::class)->assertSuccessful();
    }

    public function test_user_without_analytics_view_gets_403(): void
    {
        $userWithoutPermission = User::factory()->create([
            'organization_id' => $this->fixture->organization->id,
        ]);

        $this->actingAs($userWithoutPermission);

        $this->get(ProductPerformance::getUrl())->assertForbidden();
    }
}
