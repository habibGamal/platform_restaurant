<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Enums\OrganizationPlan;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AssistantGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
    }

    public function test_gate_denies_user_without_organization(): void
    {
        $user = User::factory()->create(['organization_id' => null]);
        $user->givePermissionTo('ai.view');

        $this->assertFalse(Gate::forUser($user)->allows('use-assistant'));
        $this->assertTrue(Gate::forUser($user)->denies('use-assistant'));
    }

    public function test_gate_denies_user_without_ai_view_permission(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->assertFalse(Gate::forUser($user)->allows('use-assistant'));
        $this->assertTrue(Gate::forUser($user)->denies('use-assistant'));
    }

    public function test_gate_denies_user_when_organization_ai_disabled(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => false,
        ]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->givePermissionTo('ai.view');

        $this->assertFalse(Gate::forUser($user)->allows('use-assistant'));
        $this->assertTrue(Gate::forUser($user)->denies('use-assistant'));
    }

    public function test_gate_denies_user_when_organization_plan_is_basic(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::BASIC,
            'ai_enabled' => true,
        ]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->givePermissionTo('ai.view');

        $this->assertFalse(Gate::forUser($user)->allows('use-assistant'));
        $this->assertTrue(Gate::forUser($user)->denies('use-assistant'));
    }

    public function test_gate_allows_org_owner_on_pro_and_ai_enabled_organization(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $owner->assignRole('org_owner');

        $this->assertTrue(Gate::forUser($owner)->allows('use-assistant'));
        $this->assertFalse(Gate::forUser($owner)->denies('use-assistant'));
    }

    public function test_gate_denies_branch_manager_without_ai_view_permission(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);
        $manager = User::factory()->create(['organization_id' => $org->id]);
        $manager->assignRole('branch_manager');

        // branch_manager does not have ai.view by default
        $this->assertFalse(Gate::forUser($manager)->allows('use-assistant'));
        $this->assertTrue(Gate::forUser($manager)->denies('use-assistant'));
    }

    public function test_gate_allows_branch_manager_with_ai_view_permission_on_pro_and_ai_enabled_organization(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);
        $manager = User::factory()->create(['organization_id' => $org->id]);
        $manager->assignRole('branch_manager');
        $manager->givePermissionTo('ai.view');

        $this->assertTrue(Gate::forUser($manager)->allows('use-assistant'));
        $this->assertFalse(Gate::forUser($manager)->denies('use-assistant'));
    }
}
