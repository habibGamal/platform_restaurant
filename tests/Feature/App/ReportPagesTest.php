<?php

declare(strict_types=1);

namespace Tests\Feature\App;

use App\Filament\App\Pages\Reports\BranchRanking;
use App\Filament\App\Pages\Reports\DataHealth;
use App\Filament\App\Pages\Reports\ExpensesPurchases;
use App\Filament\App\Pages\Reports\FoodCostWaste;
use App\Filament\App\Pages\Reports\Overview;
use App\Filament\App\Pages\Reports\ProductPerformance;
use App\Filament\App\Pages\Reports\Profitability;
use App\Filament\App\Pages\Reports\SalesComparison;
use App\Filament\App\Pages\Reports\ShiftsWeekdays;
use App\Filament\App\Pages\Reports\TargetsVsActual;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<class-string>
     */
    private const array REPORT_PAGE_CLASSES = [
        Overview::class,
        SalesComparison::class,
        Profitability::class,
        FoodCostWaste::class,
        ProductPerformance::class,
        ShiftsWeekdays::class,
        BranchRanking::class,
        TargetsVsActual::class,
        ExpensesPurchases::class,
        DataHealth::class,
    ];

    private Organization $organization;

    private Restaurant $restaurant;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->organization = Organization::factory()->create();
        $this->restaurant = Restaurant::factory()->create(['organization_id' => $this->organization->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $this->organization->id,
            'restaurant_id' => $this->restaurant->id,
        ]);
    }

    public function test_owner_can_open_all_ten_report_pages(): void
    {
        $owner = User::factory()->forOrganization($this->organization)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        foreach (self::REPORT_PAGE_CLASSES as $pageClass) {
            Livewire::test($pageClass)->assertSuccessful();
            $this->get($pageClass::getUrl())->assertSuccessful();
        }
    }

    public function test_analyst_can_open_all_ten_report_pages(): void
    {
        $analyst = User::factory()->forOrganization($this->organization)->create();
        $analyst->assignRole('org_analyst');

        $this->actingAs($analyst);

        foreach (self::REPORT_PAGE_CLASSES as $pageClass) {
            Livewire::test($pageClass)->assertSuccessful();
            $this->get($pageClass::getUrl())->assertSuccessful();
        }
    }

    public function test_branch_manager_can_open_all_ten_report_pages(): void
    {
        $manager = User::factory()->forOrganization($this->organization)->create();
        $manager->assignRole('branch_manager');
        $manager->branches()->attach($this->branch);

        $this->actingAs($manager);

        foreach (self::REPORT_PAGE_CLASSES as $pageClass) {
            Livewire::test($pageClass)->assertSuccessful();
            $this->get($pageClass::getUrl())->assertSuccessful();
        }
    }

    public function test_user_without_analytics_view_permission_gets_403(): void
    {
        $user = User::factory()->forOrganization($this->organization)->create();
        // Give user branch access but no role or analytics.view permission
        $user->branches()->attach($this->branch);

        $this->actingAs($user);

        foreach (self::REPORT_PAGE_CLASSES as $pageClass) {
            $this->get($pageClass::getUrl())->assertForbidden();
        }
    }

    public function test_operator_cannot_access_app_panel(): void
    {
        $operator = User::factory()->create(['organization_id' => null]);
        $operator->assignRole('operator');

        $this->actingAs($operator);

        $this->get('/app')->assertForbidden();
        $this->get(Overview::getUrl())->assertForbidden();
    }

    public function test_app_root_redirects_authenticated_user_to_overview(): void
    {
        $owner = User::factory()->forOrganization($this->organization)->create();
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        $response = $this->get('/app');
        $response->assertRedirect(Overview::getUrl());
    }
}
