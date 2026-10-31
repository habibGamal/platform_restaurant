<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Overview;

use App\Filament\App\Pages\Reports\Overview;
use App\Filament\App\Widgets\Reports\Overview\OverviewBranchTableWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewDataFreshnessWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewExpenseMixChartWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewKpiStatsWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewProfitBridgeChartWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewSalesByChannelChartWidget;
use App\Filament\App\Widgets\Reports\Overview\OverviewSalesTrendChartWidget;
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
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    public function test_owner_can_open_overview_page_and_render_all_widgets(): void
    {
        $this->actingAs($this->fixture->owner);

        Livewire::test(Overview::class)->assertSuccessful();
        Livewire::test(OverviewKpiStatsWidget::class)->assertSuccessful();
        Livewire::test(OverviewSalesTrendChartWidget::class)->assertSuccessful();
        Livewire::test(OverviewBranchTableWidget::class)->assertSuccessful();
        Livewire::test(OverviewProfitBridgeChartWidget::class)->assertSuccessful();
        Livewire::test(OverviewExpenseMixChartWidget::class)->assertSuccessful();
        Livewire::test(OverviewSalesByChannelChartWidget::class)->assertSuccessful();
        Livewire::test(OverviewDataFreshnessWidget::class)->assertSuccessful();

        $this->get(Overview::getUrl())->assertSuccessful();
    }

    public function test_freshness_uses_a_full_width_native_status_table(): void
    {
        $this->actingAs($this->fixture->managerA);

        $component = Livewire::test(OverviewDataFreshnessWidget::class)
            ->assertSee('fi-ta', escape: false)
            ->assertSee('آخر مزامنة')
            ->assertSee('الحالة')
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertSee('تقرير صحة البيانات');

        $this->assertSame('full', $component->instance()->getColumnSpan());
        $this->assertSame(1, app(Overview::class)->getColumns());
    }

    public function test_analyst_can_open_overview_page_and_render_all_widgets(): void
    {
        $this->actingAs($this->fixture->analyst);

        Livewire::test(Overview::class)->assertSuccessful();
        Livewire::test(OverviewKpiStatsWidget::class)->assertSuccessful();
        Livewire::test(OverviewSalesTrendChartWidget::class)->assertSuccessful();
        Livewire::test(OverviewBranchTableWidget::class)->assertSuccessful();
        Livewire::test(OverviewProfitBridgeChartWidget::class)->assertSuccessful();
        Livewire::test(OverviewExpenseMixChartWidget::class)->assertSuccessful();
        Livewire::test(OverviewSalesByChannelChartWidget::class)->assertSuccessful();
        Livewire::test(OverviewDataFreshnessWidget::class)->assertSuccessful();

        $this->get(Overview::getUrl())->assertSuccessful();
    }

    public function test_branch_manager_a_sees_only_branch_a_and_never_branch_b(): void
    {
        $this->actingAs($this->fixture->managerA);

        $branchAName = $this->fixture->branchA->name;
        $branchBName = $this->fixture->branchB->name;

        // 1. Table widget renders Branch A and does not render Branch B
        Livewire::test(OverviewBranchTableWidget::class)
            ->assertSuccessful()
            ->assertSee($branchAName)
            ->assertDontSee($branchBName);

        // 2. Sales trend per-branch does not contain Branch B
        Livewire::test(OverviewSalesTrendChartWidget::class)
            ->set('filter', 'per_branch')
            ->assertSuccessful()
            ->assertSee($branchAName)
            ->assertDontSee($branchBName);

        // 3. Freshness strip does not contain Branch B
        Livewire::test(OverviewDataFreshnessWidget::class)
            ->assertSuccessful()
            ->assertSee($branchAName)
            ->assertDontSee($branchBName);

        // 4. Full page response never contains Branch B
        $response = $this->get(Overview::getUrl());
        $response->assertSuccessful();
        $response->assertDontSee($branchBName);
    }

    public function test_user_without_analytics_view_permission_gets_403(): void
    {
        $user = User::factory()->forOrganization($this->fixture->organization)->create();
        $user->branches()->attach($this->fixture->branchA);

        $this->actingAs($user);

        $this->get(Overview::getUrl())->assertForbidden();
    }
}
