<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\DataHealth;

use App\Filament\App\Pages\Reports\DataHealth;
use App\Filament\App\Widgets\Reports\DataHealth\BranchStatusTableWidget;
use App\Filament\App\Widgets\Reports\DataHealth\CompletenessCalendarWidget;
use App\Filament\App\Widgets\Reports\DataHealth\DataHealthOverviewWidget;
use App\Filament\App\Widgets\Reports\DataHealth\IngestRejectionsTableWidget;
use App\Filament\App\Widgets\Reports\DataHealth\ProfitDriftTableWidget;
use App\Filament\App\Widgets\Reports\DataHealth\ReconciliationIssuesTableWidget;
use App\Models\ReconciliationIssue;
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
        DataHealthOverviewWidget::class,
        BranchStatusTableWidget::class,
        CompletenessCalendarWidget::class,
        ReconciliationIssuesTableWidget::class,
        IngestRejectionsTableWidget::class,
        ProfitDriftTableWidget::class,
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

        Livewire::test(DataHealth::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_analyst_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->analyst);

        Livewire::test(DataHealth::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_can_open_page_and_all_widgets_render(): void
    {
        $this->actingAs($this->fixture->managerA);

        Livewire::test(DataHealth::class)->assertSuccessful();

        foreach (self::WIDGET_CLASSES as $widgetClass) {
            Livewire::test($widgetClass)->assertSuccessful();
        }
    }

    public function test_manager_a_widgets_never_contain_branch_b_name_or_figures(): void
    {
        $this->actingAs($this->fixture->managerA);

        // Branch status table
        Livewire::test(BranchStatusTableWidget::class)
            ->assertSuccessful()
            ->assertSee($this->fixture->branchA->name)
            ->assertDontSee($this->fixture->branchB->name);

        // Completeness calendar
        Livewire::test(CompletenessCalendarWidget::class)
            ->assertSuccessful()
            ->assertSee($this->fixture->branchA->name)
            ->assertDontSee($this->fixture->branchB->name);

        // Reconciliation issues table
        Livewire::test(ReconciliationIssuesTableWidget::class)
            ->assertSuccessful()
            ->assertDontSee($this->fixture->branchB->name);

        // Rejections table
        Livewire::test(IngestRejectionsTableWidget::class)
            ->assertSuccessful()
            ->assertDontSee($this->fixture->branchB->name);

        // Profit drift table
        Livewire::test(ProfitDriftTableWidget::class)
            ->assertSuccessful()
            ->assertDontSee($this->fixture->branchB->name);
    }

    public function test_user_without_analytics_view_gets_403(): void
    {
        $unauthorizedUser = User::factory()->create([
            'organization_id' => $this->fixture->organization->id,
        ]);

        $this->actingAs($unauthorizedUser);

        $this->get('/app/reports/data-health')->assertForbidden();
    }

    public function test_owner_can_trigger_resync_via_widget_action(): void
    {
        $this->actingAs($this->fixture->owner);

        $issue = ReconciliationIssue::where('branch_id', $this->fixture->branchB->id)
            ->where('business_date', '2026-10-14')
            ->firstOrFail();

        Livewire::test(ReconciliationIssuesTableWidget::class)
            ->call('requestResync', $issue->id)
            ->assertSuccessful()
            ->assertNotified('تم إرسال طلب إعادة المزامنة للفرع');

        $this->assertDatabaseHas('branch_commands', [
            'branch_id' => $this->fixture->branchB->id,
            'type' => 'resync',
            'delivered_at' => null,
        ]);
    }

    public function test_manager_a_cannot_trigger_resync_via_widget_action(): void
    {
        $this->actingAs($this->fixture->managerA);

        $issue = ReconciliationIssue::where('branch_id', $this->fixture->branchB->id)
            ->firstOrFail();

        Livewire::test(ReconciliationIssuesTableWidget::class)
            ->call('requestResync', $issue->id)
            ->assertForbidden();
    }
}
