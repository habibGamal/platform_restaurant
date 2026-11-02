<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use App\Ai\Agents\ItemRoleClassifier;
use App\Ai\Agents\RestaurantAnalyst;
use App\Domain\Assistant\Channel;
use App\Models\AiSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Tests\TestCase;

final class OrganizationAiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_agents_keep_byo_provider_and_ordered_failover_models(): void
    {
        config(['ai.providers.openai.driver' => 'openai-compatible']);
        $org = Organization::factory()->create();
        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => 'openai', 'model' => 'primary-model', 'byo_key' => 'fake-test-key',
            'failover' => [
                ['provider' => 'anthropic', 'model' => 'backup-model'],
                ['provider' => 'gemini', 'model' => 'last-model'],
            ],
            'reasoning_level' => 'high',
        ]);

        foreach ($this->agents($org) as $agent) {
            $this->assertSame([
                "byo_openai_{$org->id}" => 'primary-model',
                'anthropic' => 'backup-model',
                'gemini' => 'last-model',
            ], $agent->provider());
            $this->assertSame('primary-model', $agent->model());
            $this->assertInstanceOf(HasProviderOptions::class, $agent);
            // The role classifier always labels at low effort; chat agents follow the organization's reasoning level.
            $expectedEffort = $agent instanceof ItemRoleClassifier ? 'low' : 'high';
            $this->assertSame(['reasoning_effort' => $expectedEffort], $agent->providerOptions("byo_openai_{$org->id}"));
            $this->assertSame(['thinking_level' => $expectedEffort], $agent->providerOptions(Lab::Gemini));
        }
    }

    public function test_both_agents_fall_back_to_configuration_without_settings(): void
    {
        config(['ai.default' => 'gemini']);
        $org = Organization::factory()->create();

        foreach ($this->agents($org) as $agent) {
            $this->assertSame('gemini', $agent->provider());
            $this->assertNull($agent->model());
        }
    }

    public function test_channel_defaults_and_explicit_reasoning_are_preserved(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->make(['organization_id' => $org->id]);
        $whatsapp = new RestaurantAnalyst($user, Channel::WhatsApp);
        $classifier = new ItemRoleClassifier($org);

        $this->assertSame(['thinking_level' => 'low'], $whatsapp->providerOptions('gemini'));
        $this->assertInstanceOf(HasProviderOptions::class, $classifier);
        $this->assertSame(['thinking_level' => 'low'], $classifier->providerOptions('gemini'));

        AiSetting::factory()->create(['organization_id' => $org->id, 'reasoning_level' => 'high']);
        $this->assertSame(['thinking_level' => 'high'], $whatsapp->providerOptions('gemini'));
        $this->assertSame(['thinking_level' => 'low'], $classifier->providerOptions('gemini'), 'The classifier ignores the chat reasoning level.');
    }

    public function test_agents_resolve_their_own_organization_settings(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        AiSetting::factory()->create(['organization_id' => $first->id, 'provider' => 'anthropic', 'model' => 'first-model', 'failover' => null]);
        AiSetting::factory()->create(['organization_id' => $second->id, 'provider' => 'gemini', 'model' => 'second-model', 'failover' => null]);

        foreach ($this->agents($first) as $agent) {
            $this->assertSame(['anthropic' => 'first-model'], $agent->provider());
        }
        foreach ($this->agents($second) as $agent) {
            $this->assertSame(['gemini' => 'second-model'], $agent->provider());
        }
    }

    /** @return list<RestaurantAnalyst|ItemRoleClassifier> */
    private function agents(Organization $organization): array
    {
        return [
            new RestaurantAnalyst(User::factory()->make(['organization_id' => $organization->id]), Channel::Chat),
            new ItemRoleClassifier($organization),
        ];
    }
}
