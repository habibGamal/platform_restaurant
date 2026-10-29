<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\TargetsVsActual;

use App\Filament\App\Pages\Reports\TargetsVsActual;
use App\Filament\App\Widgets\Reports\TargetsVsActual\TargetsBranchTableWidget;
use App\Filament\App\Widgets\Reports\TargetsVsActual\TargetsBurnUpChartWidget;
use App\Filament\App\Widgets\Reports\TargetsVsActual\TargetsGaugeOverviewWidget;
use App\Filament\App\Widgets\Reports\TargetsVsActual\TargetsHistoryWidget;
use App\Models\Target;
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

    private CarbonImmutable $frozenNow;

    /**
     * @var list<class-string>
     */
    private const array WIDGET_CLASSES = [
        TargetsGaugeOverviewWidget::class,
        TargetsBranchTableWidget::class,
        TargetsBurnUpChartWidget::class,
        TargetsHistoryWidget::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenNow = CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Cairo');
        CarbonImmutable::setTestNow($this->frozenNow);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->fixture = TwoBranchesTwoWeeks::create();

        // Create targets for fixture branches
        Target::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchA->id,
            'metric' => 'net_sales',
            'period_month' => '2026-10-01',
            'value' => 50000.00,
            'distribution' => 'flat',
        ]);

        Target::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchB->id,
            'metric' => 'net_sales',
            'period_month' => '2026-10-01',
            'value' => 20000.00,
            'distribution' => 'flat',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owner_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->owner);

        Livewire::test(TargetsVsActual::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_analyst_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->analyst);

        Livewire::test(TargetsVsActual::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->managerA);

        Livewire::test(TargetsVsActual::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_widgets_never_contain_branch_b_name_or_figures(): void
    {
        $this->actingAs($this->fixture->managerA);

        // Branch Table Widget
        Livewire::test(TargetsBranchTableWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('20,000'); // Branch B's target figure

        // Burn Up Chart Widget
        Livewire::test(TargetsBurnUpChartWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // History Widget
        Livewire::test(TargetsHistoryWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Overview Widget
        Livewire::test(TargetsGaugeOverviewWidget::class)
            ->assertSuccessful()
            ->assertDontSee('Branch B');
    }

    public function test_user_without_analytics_view_gets_403(): void
    {
        $unauthorizedUser = User::factory()->create([
            'organization_id' => $this->fixture->organization->id,
        ]);

        $this->actingAs($unauthorizedUser);

        $this->get('/app/reports/targets')->assertForbidden();
    }
}
