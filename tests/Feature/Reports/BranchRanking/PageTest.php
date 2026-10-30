<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\BranchRanking;

use App\Filament\App\Pages\Reports\BranchRanking;
use App\Filament\App\Widgets\Reports\BranchRanking\PerKpiLeadersWidget;
use App\Filament\App\Widgets\Reports\BranchRanking\RankHistoryChartWidget;
use App\Filament\App\Widgets\Reports\BranchRanking\RankingLeagueTableWidget;
use App\Filament\App\Widgets\Reports\BranchRanking\RankingPodiumWidget;
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
        RankingPodiumWidget::class,
        RankingLeagueTableWidget::class,
        PerKpiLeadersWidget::class,
        RankHistoryChartWidget::class,
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

        Livewire::test(BranchRanking::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_analyst_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->analyst);

        Livewire::test(BranchRanking::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->managerA);

        Livewire::test(BranchRanking::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_widgets_never_contain_branch_b_name_or_figures(): void
    {
        $this->actingAs($this->fixture->managerA);

        // Podium widget
        Livewire::test(RankingPodiumWidget::class)
            ->assertSuccessful()
            ->assertDontSee($this->fixture->branchB->name);

        // League table widget
        Livewire::test(RankingLeagueTableWidget::class)
            ->assertSuccessful()
            ->assertSee($this->fixture->branchA->name)
            ->assertDontSee($this->fixture->branchB->name)
            ->assertDontSee('4,160'); // Branch B's 14-day sales figure

        // Leaders widget
        Livewire::test(PerKpiLeadersWidget::class)
            ->assertSuccessful()
            ->assertDontSee($this->fixture->branchB->name);

        // History chart widget
        Livewire::test(RankHistoryChartWidget::class)
            ->assertSuccessful()
            ->assertDontSee($this->fixture->branchB->name);
    }

    public function test_user_without_analytics_view_gets_403(): void
    {
        $unauthorizedUser = User::factory()->create([
            'organization_id' => $this->fixture->organization->id,
        ]);

        $this->actingAs($unauthorizedUser);

        $this->get('/app/reports/ranking')->assertForbidden();
    }
}
