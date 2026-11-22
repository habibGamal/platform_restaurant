<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Chat;

use App\Enums\OrganizationPlan;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssistantPageRouteTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eligibleOrg;

    private User $allowedUser;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();

        $this->eligibleOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $this->allowedUser = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);
        $this->allowedUser->assignRole('org_owner');
    }

    public function test_allowed_user_gets_assistant_page_with_props(): void
    {
        $this->actingAs($this->allowedUser)
            ->get(route('assistant'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('assistant/index')
                ->has('greeting')
                ->has('starterPrompts')
                ->where('starterPrompts', fn ($prompts) => count($prompts) > 0)
                ->where('can.manageCombos', true)
                ->has('readiness')
                ->has('panelUrl')
                ->has('toolLabels')
                ->where('toolLabels.rank_items', '⏳ برتب الأصناف اللي تستاهل تركيز…')
                ->where('toolLabels.get_combo_candidates', '⏳ بجيب العروض الجاهزة بهامش ربح آمن…')
                ->where('toolLabels.get_item_card', '⏳ بجهز كارت الصنف…')
                ->where('toolLabels.get_affinities', '⏳ بشوف الأصناف اللي بتتطلب مع بعض…')
                ->where('toolLabels.get_kpi_series', '⏳ بحلل الاتجاهات…')
            );

        $manager = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);
        $manager->assignRole('branch_manager');
        $manager->givePermissionTo('ai.view');

        $this->actingAs($manager)
            ->get(route('assistant'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('assistant/index')
                ->has('greeting')
                ->has('starterPrompts')
                ->where('starterPrompts', fn ($prompts) => count($prompts) > 0)
                ->where('can.manageCombos', false)
                ->has('readiness')
                ->has('panelUrl')
            );
    }

    public function test_user_without_assistant_access_is_forbidden(): void
    {
        $deniedUser = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);

        $this->actingAs($deniedUser)
            ->get('/assistant')
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/assistant')
            ->assertRedirect(route('login'));
    }
}
