<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\AccessibleBranches;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessibleBranchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_sees_all_org_branches_not_other_orgs(): void
    {
        $orgA = Organization::factory()->create();
        $restA = Restaurant::factory()->create(['organization_id' => $orgA->id]);
        $branchA1 = Branch::factory()->create(['organization_id' => $orgA->id, 'restaurant_id' => $restA->id]);
        $branchA2 = Branch::factory()->create(['organization_id' => $orgA->id, 'restaurant_id' => $restA->id]);

        $orgB = Organization::factory()->create();
        $restB = Restaurant::factory()->create(['organization_id' => $orgB->id]);
        $branchB1 = Branch::factory()->create(['organization_id' => $orgB->id, 'restaurant_id' => $restB->id]);

        $owner = User::factory()->forOrganization($orgA)->create();
        $owner->assignRole('org_owner');

        $accessibleIds = AccessibleBranches::ids($owner);

        $this->assertCount(2, $accessibleIds);
        $this->assertContains($branchA1->id, $accessibleIds);
        $this->assertContains($branchA2->id, $accessibleIds);
        $this->assertNotContains($branchB1->id, $accessibleIds);

        $queryIds = AccessibleBranches::query($owner)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$branchA1->id, $branchA2->id], $queryIds);
    }

    public function test_analyst_sees_all_org_branches_not_other_orgs(): void
    {
        $orgA = Organization::factory()->create();
        $restA = Restaurant::factory()->create(['organization_id' => $orgA->id]);
        $branchA1 = Branch::factory()->create(['organization_id' => $orgA->id, 'restaurant_id' => $restA->id]);

        $orgB = Organization::factory()->create();
        $restB = Restaurant::factory()->create(['organization_id' => $orgB->id]);
        $branchB1 = Branch::factory()->create(['organization_id' => $orgB->id, 'restaurant_id' => $restB->id]);

        $analyst = User::factory()->forOrganization($orgA)->create();
        $analyst->assignRole('org_analyst');

        $accessibleIds = AccessibleBranches::ids($analyst);

        $this->assertCount(1, $accessibleIds);
        $this->assertContains($branchA1->id, $accessibleIds);
        $this->assertNotContains($branchB1->id, $accessibleIds);
    }

    public function test_manager_sees_only_assigned_branches_in_same_organization(): void
    {
        $orgA = Organization::factory()->create();
        $restA = Restaurant::factory()->create(['organization_id' => $orgA->id]);
        $branchA1 = Branch::factory()->create(['organization_id' => $orgA->id, 'restaurant_id' => $restA->id]);
        $branchA2 = Branch::factory()->create(['organization_id' => $orgA->id, 'restaurant_id' => $restA->id]);

        $orgB = Organization::factory()->create();
        $restB = Restaurant::factory()->create(['organization_id' => $orgB->id]);
        $branchB1 = Branch::factory()->create(['organization_id' => $orgB->id, 'restaurant_id' => $restB->id]);

        $manager = User::factory()->forOrganization($orgA)->create();
        $manager->assignRole('branch_manager');

        // Assign branchA1 and mistakenly branchB1 (different org)
        $manager->branches()->sync([$branchA1->id, $branchB1->id]);

        $accessibleIds = AccessibleBranches::ids($manager);

        $this->assertCount(1, $accessibleIds);
        $this->assertContains($branchA1->id, $accessibleIds);
        $this->assertNotContains($branchA2->id, $accessibleIds);
        $this->assertNotContains($branchB1->id, $accessibleIds);
    }

    public function test_user_without_role_sees_none(): void
    {
        $orgA = Organization::factory()->create();
        $restA = Restaurant::factory()->create(['organization_id' => $orgA->id]);
        Branch::factory()->create(['organization_id' => $orgA->id, 'restaurant_id' => $restA->id]);

        $user = User::factory()->forOrganization($orgA)->create();

        $this->assertEmpty(AccessibleBranches::ids($user));
        $this->assertCount(0, AccessibleBranches::query($user)->get());
    }

    public function test_user_with_null_organization_sees_none(): void
    {
        $orgA = Organization::factory()->create();
        $restA = Restaurant::factory()->create(['organization_id' => $orgA->id]);
        Branch::factory()->create(['organization_id' => $orgA->id, 'restaurant_id' => $restA->id]);

        $operator = User::factory()->create(['organization_id' => null]);
        $operator->assignRole('operator');

        $this->assertEmpty(AccessibleBranches::ids($operator));
        $this->assertCount(0, AccessibleBranches::query($operator)->get());
    }
}
