<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Domain\Metrics\Period\PeriodPreset;
use App\Filament\App\Pages\Reports\BranchRanking;
use App\Filament\App\Pages\Reports\ExpensesPurchases;
use App\Filament\App\Pages\Reports\Overview;
use App\Filament\App\Pages\Reports\ProductPerformance;
use App\Filament\App\Pages\Reports\SalesComparison;
use App\Filament\App\Pages\Reports\ShiftsWeekdays;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportFiltersFormTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Restaurant $restaurantA;

    private Branch $branchA1;

    private Branch $branchA2;

    private Branch $branchB1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->organizationA = Organization::factory()->create();
        $this->restaurantA = Restaurant::factory()->create(['organization_id' => $this->organizationA->id]);
        $this->branchA1 = Branch::factory()->create([
            'organization_id' => $this->organizationA->id,
            'restaurant_id' => $this->restaurantA->id,
            'name' => 'Branch A1',
        ]);
        $this->branchA2 = Branch::factory()->create([
            'organization_id' => $this->organizationA->id,
            'restaurant_id' => $this->restaurantA->id,
            'name' => 'Branch A2',
        ]);

        $orgB = Organization::factory()->create();
        $restB = Restaurant::factory()->create(['organization_id' => $orgB->id]);
        $this->branchB1 = Branch::factory()->create([
            'organization_id' => $orgB->id,
            'restaurant_id' => $restB->id,
            'name' => 'Branch B1',
        ]);
    }

    public function test_branch_options_contain_only_accessible_branches(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $component = Livewire::test(Overview::class);
        /** @var Overview $page */
        $page = $component->instance();

        /** @var Select $branchesField */
        $branchesField = $page->getFiltersForm()->getComponent('branches');
        $options = $branchesField->getOptions();

        $this->assertArrayHasKey($this->branchA1->id, $options);
        $this->assertArrayHasKey($this->branchA2->id, $options);
        $this->assertArrayNotHasKey($this->branchB1->id, $options);
        $this->assertFalse($branchesField->isHidden());
    }

    public function test_manager_with_one_branch_has_no_branch_select(): void
    {
        $manager = User::factory()->forOrganization($this->organizationA)->create();
        $manager->assignRole('branch_manager');
        $manager->branches()->attach($this->branchA1);

        $this->actingAs($manager);

        $component = Livewire::test(Overview::class);
        /** @var Overview $page */
        $page = $component->instance();

        $this->assertNull($page->getFiltersForm()->getComponent('branches'));

        $branchesField = $page->getFiltersForm()->getComponent('branches', withHidden: true);
        $this->assertInstanceOf(Select::class, $branchesField);
        $this->assertTrue($branchesField->isHidden());
    }

    public function test_query_string_produces_matching_report_filters_on_page(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $component = Livewire::withQueryParams([
            'preset' => 'custom',
            'from' => '2026-10-01',
            'to' => '2026-10-07',
            'branches' => "{$this->branchA1->id},{$this->branchA2->id}",
        ])->test(Overview::class);

        /** @var Overview $page */
        $page = $component->instance();
        $filters = $page->reportFilters();

        $this->assertSame(PeriodPreset::custom, $filters->preset);
        $this->assertSame('2026-10-01', $filters->period->from->toDateString());
        $this->assertSame('2026-10-07', $filters->period->to->toDateString());
        $this->assertEqualsCanonicalizing([$this->branchA1->id, $this->branchA2->id], $filters->branchIds);
    }

    public function test_editing_draft_filters_does_not_mutate_applied_filters_or_session_until_apply(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $component = Livewire::test(Overview::class);
        /** @var Overview $page */
        $page = $component->instance();
        $sessionKey = $page->getFiltersSessionKey();

        $this->assertSame(PeriodPreset::today->value, $page->filters['preset']);
        $this->assertSame(PeriodPreset::today, $page->reportFilters()->preset);

        // Mutate draft filters
        $component->set('draftFilters.preset', PeriodPreset::yesterday->value);

        // Verify draft state changed but applied state and report filters are untouched
        $this->assertSame(PeriodPreset::yesterday->value, $component->get('draftFilters.preset'));
        $this->assertSame(PeriodPreset::today->value, $component->get('filters.preset'));
        $this->assertSame(PeriodPreset::today, $page->reportFilters()->preset);

        // Verify session applied state is still 'today'
        $sessionData = session()->get($sessionKey);
        $this->assertSame(PeriodPreset::today->value, $sessionData['preset']);
    }

    public function test_apply_filters_commits_draft_state_to_applied_and_session(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $component = Livewire::test(Overview::class);
        /** @var Overview $page */
        $page = $component->instance();
        $sessionKey = $page->getFiltersSessionKey();

        // Change preset and branches in draft state
        $component->set('draftFilters.preset', PeriodPreset::last_30_days->value);
        $component->set('draftFilters.branches', [$this->branchA1->id]);

        // Explicitly trigger Apply action
        $component->call('applyFilters');

        // Both applied filters and report filters are updated
        $this->assertSame(PeriodPreset::last_30_days->value, $component->get('filters.preset'));
        $this->assertEquals([$this->branchA1->id], $component->get('filters.branches'));
        /** @var Overview $updatedPage */
        $updatedPage = $component->instance();
        $this->assertSame(PeriodPreset::last_30_days, $updatedPage->reportFilters()->preset);
        $this->assertEquals([$this->branchA1->id], $updatedPage->reportFilters()->branchIds);

        // Session is committed
        $sessionData = session()->get($sessionKey);
        $this->assertSame(PeriodPreset::last_30_days->value, $sessionData['preset']);
        $this->assertEquals([$this->branchA1->id], $sessionData['branches']);
    }

    public function test_apply_filters_validates_custom_dates(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $component = Livewire::test(Overview::class);

        // 1. Missing dates for custom preset
        $component->set('draftFilters.preset', PeriodPreset::custom->value)
            ->set('draftFilters.from', null)
            ->set('draftFilters.to', null)
            ->call('applyFilters')
            ->assertHasErrors(['draftFilters.from', 'draftFilters.to']);

        // Applied state remains unchanged
        $this->assertNotSame(PeriodPreset::custom->value, $component->get('filters.preset'));

        // 2. Start date after end date
        $component->set('draftFilters.from', '2026-10-15')
            ->set('draftFilters.to', '2026-10-01')
            ->call('applyFilters')
            ->assertHasErrors(['draftFilters.from']);

        $this->assertNotSame(PeriodPreset::custom->value, $component->get('filters.preset'));

        // 3. Valid date range passes
        $component->set('draftFilters.from', '2026-10-01')
            ->set('draftFilters.to', '2026-10-15')
            ->call('applyFilters')
            ->assertHasNoErrors();

        $this->assertSame(PeriodPreset::custom->value, $component->get('filters.preset'));
        $this->assertSame('2026-10-01', $component->get('filters.from'));
        $this->assertSame('2026-10-15', $component->get('filters.to'));
    }

    public function test_reset_filters_restores_page_defaults(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $component = Livewire::test(Overview::class);
        $component->set('draftFilters.preset', PeriodPreset::last_30_days->value);
        $component->set('draftFilters.branches', [$this->branchA1->id]);
        $component->call('applyFilters');

        $this->assertSame(PeriodPreset::last_30_days->value, $component->get('filters.preset'));

        // Reset
        $component->call('resetFilters');

        $this->assertSame(PeriodPreset::today->value, $component->get('filters.preset'));
        $this->assertSame(PeriodPreset::today->value, $component->get('draftFilters.preset'));
        $this->assertEmpty($component->get('filters.branches'));
        $this->assertEmpty($component->get('draftFilters.branches'));
    }

    public function test_branch_ranking_defaults_and_reset(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $component = Livewire::test(BranchRanking::class);

        // BranchRanking defaults: last_month and lfl = true
        $this->assertSame(PeriodPreset::last_month->value, $component->get('filters.preset'));
        $this->assertTrue($component->get('filters.lfl'));
        $this->assertSame(PeriodPreset::last_month->value, $component->get('draftFilters.preset'));
        $this->assertTrue($component->get('draftFilters.lfl'));

        // Edit and apply
        $component->set('draftFilters.preset', PeriodPreset::yesterday->value);
        $component->set('draftFilters.lfl', false);
        $component->call('applyFilters');

        $this->assertSame(PeriodPreset::yesterday->value, $component->get('filters.preset'));
        $this->assertFalse($component->get('filters.lfl'));

        // Reset restores BranchRanking defaults
        $component->call('resetFilters');

        $this->assertSame(PeriodPreset::last_month->value, $component->get('filters.preset'));
        $this->assertTrue($component->get('filters.lfl'));
    }

    public function test_shifts_weekdays_defaults_and_report_specific_filters(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $component = Livewire::test(ShiftsWeekdays::class);

        // ShiftsWeekdays defaults: last_8_weeks and exclude_events = true
        $this->assertSame(PeriodPreset::last_8_weeks->value, $component->get('filters.preset'));
        $this->assertTrue($component->get('filters.exclude_events'));
        $this->assertSame(PeriodPreset::last_8_weeks->value, $component->get('draftFilters.preset'));
        $this->assertTrue($component->get('draftFilters.exclude_events'));

        // Edit without apply
        $component->set('draftFilters.exclude_events', false);
        $component->set('draftFilters.channel', 'delivery');

        $this->assertTrue($component->get('filters.exclude_events'));
        $this->assertNull($component->get('filters.channel'));

        // Apply
        $component->call('applyFilters');

        $this->assertFalse($component->get('filters.exclude_events'));
        $this->assertSame('delivery', $component->get('filters.channel'));

        // Reset
        $component->call('resetFilters');

        $this->assertSame(PeriodPreset::last_8_weeks->value, $component->get('filters.preset'));
        $this->assertTrue($component->get('filters.exclude_events'));
        $this->assertNull($component->get('filters.channel'));
    }

    public function test_other_report_subclass_defaults(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        // ProductPerformance defaults to last_30_days
        $productsComponent = Livewire::test(ProductPerformance::class);
        $this->assertSame(PeriodPreset::last_30_days->value, $productsComponent->get('filters.preset'));
        $this->assertSame(PeriodPreset::last_30_days->value, $productsComponent->get('draftFilters.preset'));

        // ExpensesPurchases defaults to this_month
        $expensesComponent = Livewire::test(ExpensesPurchases::class);
        $this->assertSame(PeriodPreset::this_month->value, $expensesComponent->get('filters.preset'));
        $this->assertSame(PeriodPreset::this_month->value, $expensesComponent->get('draftFilters.preset'));

        // SalesComparison supports channel
        $salesComponent = Livewire::test(SalesComparison::class);
        $salesComponent->set('draftFilters.channel', 'dine_in');
        $salesComponent->call('applyFilters');
        $this->assertSame('dine_in', $salesComponent->get('filters.channel'));
    }

    public function test_session_persistence_restores_on_subsequent_mount(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $initialComponent = Livewire::test(Overview::class);
        $sessionKey = $initialComponent->instance()->getFiltersSessionKey();

        // Put saved filters into session
        session()->put($sessionKey, [
            'preset' => PeriodPreset::yesterday->value,
            'branches' => [$this->branchA1->id],
        ]);

        // Next visit without query params restores session
        $secondComponent = Livewire::test(Overview::class);
        $this->assertSame(PeriodPreset::yesterday->value, $secondComponent->get('filters.preset'));
        $this->assertSame(PeriodPreset::yesterday->value, $secondComponent->get('draftFilters.preset'));
        $this->assertEquals([$this->branchA1->id], $secondComponent->get('filters.branches'));
    }

    public function test_url_query_overrides_session_on_mount(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $initialComponent = Livewire::test(Overview::class);
        $sessionKey = $initialComponent->instance()->getFiltersSessionKey();

        session()->put($sessionKey, [
            'preset' => PeriodPreset::yesterday->value,
        ]);

        // Mount with query param overriding session
        $component = Livewire::withQueryParams([
            'preset' => 'last_7_days',
        ])->test(Overview::class);

        $this->assertSame('last_7_days', $component->get('filters.preset'));
        $this->assertSame('last_7_days', $component->get('draftFilters.preset'));
    }

    public function test_filament_actions_apply_and_reset_can_be_mounted_and_called(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $component = Livewire::test(Overview::class);

        // Mutate draft state
        $component->set('draftFilters.preset', PeriodPreset::last_30_days->value);

        // Call via Filament Action
        $component->callAction('apply');

        $this->assertSame(PeriodPreset::last_30_days->value, $component->get('filters.preset'));

        // Reset via Filament Action
        $component->callAction('reset');

        $this->assertSame(PeriodPreset::today->value, $component->get('filters.preset'));
        $this->assertSame(PeriodPreset::today->value, $component->get('draftFilters.preset'));
    }

    public function test_apply_rejects_invalid_calendar_dates_and_unknown_options(): void
    {
        $owner = User::factory()->forOrganization($this->organizationA)->create();
        $owner->assignRole('org_owner');
        $this->actingAs($owner);

        Livewire::test(Overview::class)
            ->set('draftFilters.preset', 'custom')
            ->set('draftFilters.from', '2026-02-30')
            ->set('draftFilters.to', '2026-03-05')
            ->call('applyFilters')
            ->assertHasErrors(['draftFilters.from']);

        Livewire::test(Overview::class)
            ->set('draftFilters.preset', 'invalid-preset')
            ->call('applyFilters')
            ->assertHasErrors(['draftFilters.preset']);
    }
}
