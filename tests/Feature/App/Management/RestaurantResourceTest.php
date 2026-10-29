<?php

namespace Tests\Feature\App\Management;

use App\Filament\App\Resources\RestaurantResource\Pages\CreateRestaurant;
use App\Filament\App\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class RestaurantResourceTest extends ManagementTestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $analystA;

    private User $managerA;

    private Restaurant $restA;

    private Restaurant $restB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->ownerA = User::factory()->forOrganization($this->orgA)->create();
        $this->ownerA->assignRole('org_owner');

        $this->analystA = User::factory()->forOrganization($this->orgA)->create();
        $this->analystA->assignRole('org_analyst');

        $this->managerA = User::factory()->forOrganization($this->orgA)->create();
        $this->managerA->assignRole('branch_manager');

        $this->restA = Restaurant::factory()->create([
            'organization_id' => $this->orgA->id,
            'name' => 'مطعم المنظمة الأولى',
        ]);

        $this->restB = Restaurant::factory()->create([
            'organization_id' => $this->orgB->id,
            'name' => 'مطعم المنظمة الثانية',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_list_scoped_to_own_organization(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(ListRestaurants::class)
            ->assertCanSeeTableRecords([$this->restA])
            ->assertCanNotSeeTableRecords([$this->restB]);
    }

    public function test_analyst_and_manager_cannot_manage_restaurants(): void
    {
        $this->actingAs($this->analystA);
        $this->assertFalse($this->analystA->can('viewAny', Restaurant::class));
        $this->assertFalse($this->analystA->can('create', Restaurant::class));

        $this->actingAs($this->managerA);
        $this->assertFalse($this->managerA->can('viewAny', Restaurant::class));
        $this->assertFalse($this->managerA->can('create', Restaurant::class));
    }

    public function test_owner_can_create_restaurant(): void
    {
        $this->actingAs($this->ownerA);

        Livewire::test(CreateRestaurant::class)
            ->fillForm([
                'name' => 'مطعم جديد',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('restaurants', [
            'organization_id' => $this->orgA->id,
            'name' => 'مطعم جديد',
        ]);
    }
}
