<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\BranchSet;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authenticated_user_with_organization_is_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $restA = Restaurant::factory()->create(['organization_id' => $orgA->id]);
        $branchA = Branch::factory()->create(['organization_id' => $orgA->id, 'restaurant_id' => $restA->id]);
        $setA = BranchSet::factory()->create(['organization_id' => $orgA->id]);

        $orgB = Organization::factory()->create();
        $restB = Restaurant::factory()->create(['organization_id' => $orgB->id]);
        $branchB = Branch::factory()->create(['organization_id' => $orgB->id, 'restaurant_id' => $restB->id]);
        $setB = BranchSet::factory()->create(['organization_id' => $orgB->id]);

        $userA = User::factory()->forOrganization($orgA)->create();
        $this->actingAs($userA);

        $this->assertEquals([$restA->id], Restaurant::pluck('id')->all());
        $this->assertEquals([$branchA->id], Branch::pluck('id')->all());
        $this->assertEquals([$setA->id], BranchSet::pluck('id')->all());
    }

    public function test_unauthenticated_or_operator_without_org_is_not_scoped(): void
    {
        $orgA = Organization::factory()->create();
        $restA = Restaurant::factory()->create(['organization_id' => $orgA->id]);
        $branchA = Branch::factory()->create(['organization_id' => $orgA->id, 'restaurant_id' => $restA->id]);

        $orgB = Organization::factory()->create();
        $restB = Restaurant::factory()->create(['organization_id' => $orgB->id]);
        $branchB = Branch::factory()->create(['organization_id' => $orgB->id, 'restaurant_id' => $restB->id]);

        // Unauthenticated
        $this->assertCount(2, Restaurant::all());
        $this->assertCount(2, Branch::all());

        // Acting as operator (organization_id === null)
        $operator = User::factory()->create(['organization_id' => null]);
        $operator->assignRole('operator');
        $this->actingAs($operator);

        $this->assertCount(2, Restaurant::all());
        $this->assertCount(2, Branch::all());
    }

    public function test_creating_model_without_organization_id_auto_fills_from_authenticated_user(): void
    {
        $orgA = Organization::factory()->create();
        $restA = Restaurant::factory()->create(['organization_id' => $orgA->id]);
        $userA = User::factory()->forOrganization($orgA)->create();

        $this->actingAs($userA);

        $restaurant = Restaurant::create(['name' => 'Auto Scoped Restaurant']);
        $this->assertEquals($orgA->id, $restaurant->organization_id);

        $branch = Branch::create([
            'restaurant_id' => $restA->id,
            'name' => 'Auto Scoped Branch',
        ]);
        $this->assertEquals($orgA->id, $branch->organization_id);

        $branchSet = BranchSet::create([
            'name' => 'Auto Scoped Set',
        ]);
        $this->assertEquals($orgA->id, $branchSet->organization_id);
    }
}
