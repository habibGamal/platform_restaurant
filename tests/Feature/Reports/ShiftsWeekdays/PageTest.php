<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\ShiftsWeekdays;

use App\Filament\App\Pages\Reports\ShiftsWeekdays;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\CashierSummaryTableWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\HourlyHeatmapWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\PeakHoursChartWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\ShiftsKpiOverviewWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\ShiftSlotsTableWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\ShiftsTableWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\WeekdayProfileChartWidget;
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
        ShiftsKpiOverviewWidget::class,
        WeekdayProfileChartWidget::class,
        HourlyHeatmapWidget::class,
        PeakHoursChartWidget::class,
        ShiftSlotsTableWidget::class,
        CashierSummaryTableWidget::class,
        ShiftsTableWidget::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenNow = CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Cairo');
        CarbonImmutable::setTestNow($this->frozenNow);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owner_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->owner);

        Livewire::test(ShiftsWeekdays::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_analyst_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->analyst);

        Livewire::test(ShiftsWeekdays::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->managerA);

        Livewire::test(ShiftsWeekdays::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_widgets_never_contain_branch_b_name_or_figures(): void
    {
        $this->actingAs($this->fixture->managerA);

        // Weekday profile chart
        Livewire::test(WeekdayProfileChartWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Hourly heatmap widget
        Livewire::test(HourlyHeatmapWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Peak hours chart widget
        Livewire::test(PeakHoursChartWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Shift slots table widget
        Livewire::test(ShiftSlotsTableWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Cashier summary table widget
        Livewire::test(CashierSummaryTableWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('user-b-cashier-1')
            ->assertDontSee('user-b-cashier-2');

        // Shifts table widget
        Livewire::test(ShiftsTableWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('user-b-cashier-1');
    }

    public function test_user_without_analytics_view_gets_403(): void
    {
        $unauthorizedUser = User::factory()->create([
            'organization_id' => $this->fixture->organization->id,
        ]);

        $this->actingAs($unauthorizedUser);

        $this->get('/app/reports/shifts')->assertForbidden();
    }
}
