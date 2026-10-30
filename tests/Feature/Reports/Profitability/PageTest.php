<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Profitability;

use App\Filament\App\Pages\Reports\Profitability;
use App\Filament\App\Widgets\Reports\Profitability\MarginLadderChartWidget;
use App\Filament\App\Widgets\Reports\Profitability\MarginLeakageChartWidget;
use App\Filament\App\Widgets\Reports\Profitability\MarginTrendChartWidget;
use App\Filament\App\Widgets\Reports\Profitability\ProfitabilityKpiStatsWidget;
use App\Filament\App\Widgets\Reports\Profitability\ProfitabilityPlMatrixWidget;
use App\Filament\App\Widgets\Reports\Profitability\ProfitSalesScatterChartWidget;
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

        $this->get(Profitability::getUrl())->assertSuccessful();

        Livewire::test(Profitability::class)->assertSuccessful();
        Livewire::test(ProfitabilityKpiStatsWidget::class)->assertSuccessful();
        Livewire::test(ProfitabilityPlMatrixWidget::class)->assertSuccessful();
        Livewire::test(MarginLadderChartWidget::class)->assertSuccessful();
        Livewire::test(MarginLeakageChartWidget::class)->assertSuccessful();
        Livewire::test(MarginTrendChartWidget::class)->assertSuccessful();
        Livewire::test(ProfitSalesScatterChartWidget::class)->assertSuccessful();
    }

    public function test_analyst_can_open_page_and_render_all_widgets(): void
    {
        $this->actingAs($this->fixture->analyst);

        $this->get(Profitability::getUrl())->assertSuccessful();

        Livewire::test(Profitability::class)->assertSuccessful();
        Livewire::test(ProfitabilityKpiStatsWidget::class)->assertSuccessful();
        Livewire::test(ProfitabilityPlMatrixWidget::class)->assertSuccessful();
        Livewire::test(MarginLadderChartWidget::class)->assertSuccessful();
        Livewire::test(MarginLeakageChartWidget::class)->assertSuccessful();
        Livewire::test(MarginTrendChartWidget::class)->assertSuccessful();
        Livewire::test(ProfitSalesScatterChartWidget::class)->assertSuccessful();
    }

    public function test_manager_a_widgets_never_contain_branch_b_name_or_figures(): void
    {
        $this->actingAs($this->fixture->managerA);

        $this->get(Profitability::getUrl())->assertSuccessful();

        Livewire::test(Profitability::class)
            ->assertSuccessful()
            ->assertDontSee('Branch B');

        // PL matrix widget should show Branch A, but NEVER Branch B or Group Total
        Livewire::test(ProfitabilityPlMatrixWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('الإجمالي (المجموعة)')
            ->assertDontSee('4,160.00') // Branch B net sales
            ->assertDontSee('1,745.00'); // Branch B net profit

        // Charts
        Livewire::test(MarginLadderChartWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('متوسط المجموعة');

        Livewire::test(MarginLeakageChartWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        Livewire::test(MarginTrendChartWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        Livewire::test(ProfitSalesScatterChartWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');
    }

    public function test_user_without_analytics_view_permission_gets_403(): void
    {
        $unauthorizedUser = User::factory()->forOrganization($this->fixture->organization)->create();
        $unauthorizedUser->branches()->attach($this->fixture->branchA);

        $this->actingAs($unauthorizedUser);

        $this->get(Profitability::getUrl())->assertForbidden();
    }
}
