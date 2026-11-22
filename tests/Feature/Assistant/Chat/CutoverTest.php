<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Chat;

use App\Enums\OrganizationPlan;
use App\Filament\App\Pages\Assistant as AssistantPage;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CutoverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eligibleOrg;

    private User $allowedUser;

    private User $deniedUser;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();

        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->eligibleOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $this->allowedUser = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);
        $this->allowedUser->assignRole('org_owner');

        $this->deniedUser = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);
    }

    public function test_filament_assistant_page_redirects_to_react_page_when_enabled(): void
    {
        config(['assistant.react_ui' => true]);

        $this->actingAs($this->allowedUser);

        $this->get(AssistantPage::getUrl())
            ->assertRedirect(route('assistant'));

        Livewire::test(AssistantPage::class)
            ->assertRedirect(route('assistant'));
    }

    public function test_unauthorized_user_is_forbidden_even_when_react_ui_is_enabled(): void
    {
        config(['assistant.react_ui' => true]);

        $this->actingAs($this->deniedUser);

        $this->get(AssistantPage::getUrl())
            ->assertForbidden();

        Livewire::test(AssistantPage::class)
            ->assertForbidden();
    }

    public function test_livewire_page_still_works_when_disabled(): void
    {
        config(['assistant.react_ui' => false]);

        $this->actingAs($this->allowedUser);

        $this->get(AssistantPage::getUrl())
            ->assertOk();

        Livewire::test(AssistantPage::class)
            ->assertOk();
    }

    public function test_navigation_remains_registered_when_react_ui_is_enabled(): void
    {
        config(['assistant.react_ui' => true]);

        $this->actingAs($this->allowedUser);

        $this->assertTrue(AssistantPage::shouldRegisterNavigation());
    }

    public function test_map_tool_status_reads_from_assistant_home_labels(): void
    {
        $page = new AssistantPage;
        $reflection = new \ReflectionMethod(AssistantPage::class, 'mapToolStatus');

        $this->assertSame('⏳ بحسب المؤشرات…', $reflection->invoke($page, 'get_kpis'));
        $this->assertSame('⏳ برتب الأصناف اللي تستاهل تركيز…', $reflection->invoke($page, 'rank_items'));
        $this->assertSame('⏳ بجهز البيانات…', $reflection->invoke($page, 'unknown_tool'));
    }
}
