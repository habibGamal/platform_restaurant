<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Domain\Metrics\Period\PeriodPreset;
use App\Filament\App\Pages\Reports\Overview;
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

        // Filament removes hidden components from getComponent() by default
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
}
