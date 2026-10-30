<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\SalesComparison;

use App\Filament\App\Pages\Reports\SalesComparison;
use App\Filament\App\Widgets\Reports\SalesComparison\DiscountIntensityWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\GrowthDecompositionTableWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\SalesKpiMatrixWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\SalesKpiOverviewWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\SalesMixChartWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\SalesTrendChartWidget;
use App\Filament\App\Widgets\Reports\SalesComparison\TrafficBasketScatterWidget;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        SalesKpiOverviewWidget::class,
        SalesKpiMatrixWidget::class,
        TrafficBasketScatterWidget::class,
        SalesTrendChartWidget::class,
        GrowthDecompositionTableWidget::class,
        SalesMixChartWidget::class,
        DiscountIntensityWidget::class,
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

        Livewire::test(SalesComparison::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_analyst_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->analyst);

        Livewire::test(SalesComparison::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->managerA);

        Livewire::test(SalesComparison::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_widgets_never_contain_branch_b_name_or_figures(): void
    {
        $this->actingAs($this->fixture->managerA);

        // Matrix widget: in a 2-branch org, group average is suppressed for manager A to prevent single-other-branch inference
        Livewire::test(SalesKpiMatrixWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('متوسط المجموعة')
            ->assertDontSee('Branch B')
            ->assertDontSee('1,920'); // Branch B's sales figure

        // Scatter widget
        Livewire::test(TrafficBasketScatterWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Trend widget
        Livewire::test(SalesTrendChartWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Growth Decomposition widget
        Livewire::test(GrowthDecompositionTableWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Mix widget
        Livewire::test(SalesMixChartWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');

        // Discount Intensity widget
        Livewire::test(DiscountIntensityWidget::class)
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B');
    }

    public function test_user_without_analytics_view_gets_403(): void
    {
        $unauthorizedUser = User::factory()->create([
            'organization_id' => $this->fixture->organization->id,
        ]);

        $this->actingAs($unauthorizedUser);

        $this->get('/app/reports/sales')->assertForbidden();
    }

    private function createBranchCWithDailyData(): Branch
    {
        $branchC = Branch::create([
            'organization_id' => $this->fixture->organization->id,
            'restaurant_id' => $this->fixture->restaurant->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Branch C',
            'status' => 'active',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-01-01',
        ]);

        for ($day = 3; $day <= 16; $day++) {
            $dayStr = str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            DB::table('agg_branch_daily')->insert([
                'organization_id' => $this->fixture->organization->id,
                'branch_id' => $branchC->id,
                'restaurant_id' => $this->fixture->restaurant->id,
                'business_date' => "2026-10-{$dayStr}",
                'weekday' => ($day - 3) % 7,
                'orders_count' => 10,
                'net_sales' => 1000.00,
                'net_sales_after_returns' => 1000.00,
                'gross_sales' => 1050.00,
                'discounts' => 50.00,
                'returns_amount' => 0.00,
                'cancelled_count' => 0,
                'cogs' => 400.00,
                'gross_profit' => 600.00,
                'expenses_total' => 100.00,
                'labor_cost' => 0.00,
                'rent_cost' => 0.00,
                'other_expenses' => 100.00,
                'waste_cost' => 0.00,
                'inventory_variance_cost' => 0.00,
                'net_profit' => 500.00,
                'sales_by_type' => json_encode([
                    'dine_in' => ['orders' => 8, 'net_sales' => 800.00],
                    'takeaway' => ['orders' => 2, 'net_sales' => 200.00],
                ]),
                'sales_by_method' => json_encode([
                    'cash' => 600.00,
                    'card' => 400.00,
                ]),
            ]);
        }

        return $branchC;
    }

    public function test_manager_a_widgets_suppress_group_average_and_other_branches_in_three_branch_org(): void
    {
        $this->createBranchCWithDailyData();

        $this->actingAs($this->fixture->managerA);

        // Matrix widget with last_week preset: suppressed group average and no leakage of Branch B or C
        Livewire::test(SalesKpiMatrixWidget::class, ['pageFilters' => ['preset' => 'last_week']])
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('متوسط المجموعة')
            ->assertDontSee('Branch B')
            ->assertDontSee('Branch C')
            ->assertDontSee('1,920')
            ->assertDontSee('7,000');

        // Other widgets
        Livewire::test(TrafficBasketScatterWidget::class, ['pageFilters' => ['preset' => 'last_week']])
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('Branch C');

        Livewire::test(SalesTrendChartWidget::class, ['pageFilters' => ['preset' => 'last_week']])
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('Branch C');

        Livewire::test(GrowthDecompositionTableWidget::class, ['pageFilters' => ['preset' => 'last_week']])
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('Branch C');

        Livewire::test(SalesMixChartWidget::class, ['pageFilters' => ['preset' => 'last_week']])
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('Branch C');

        Livewire::test(DiscountIntensityWidget::class, ['pageFilters' => ['preset' => 'last_week']])
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertDontSee('Branch B')
            ->assertDontSee('Branch C');
    }

    public function test_owner_and_analyst_widgets_render_group_average_and_all_branches_in_three_branch_org(): void
    {
        $this->createBranchCWithDailyData();

        // Owner: sees all 3 branches + group average
        $this->actingAs($this->fixture->owner);
        Livewire::test(SalesComparison::class)->assertSuccessful();
        Livewire::test(SalesKpiMatrixWidget::class, ['pageFilters' => ['preset' => 'last_week']])
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertSee('Branch B')
            ->assertSee('Branch C')
            ->assertSee('متوسط المجموعة');

        // Analyst: sees all 3 branches + group average
        $this->actingAs($this->fixture->analyst);
        Livewire::test(SalesComparison::class)->assertSuccessful();
        Livewire::test(SalesKpiMatrixWidget::class, ['pageFilters' => ['preset' => 'last_week']])
            ->assertSuccessful()
            ->assertSee('Branch A')
            ->assertSee('Branch B')
            ->assertSee('Branch C')
            ->assertSee('متوسط المجموعة');
    }
}
