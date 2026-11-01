<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Settings;

use App\Domain\Assistant\AssistantReadiness;
use App\Models\AiSetting;
use App\Models\Organization;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class AssistantReadinessTest extends TestCase
{
    use RefreshDatabase;

    private AssistantReadiness $readiness;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->readiness = new AssistantReadiness;
    }

    public function test_reason_not_configured_when_no_ai_settings_and_default_provider_has_no_key(): void
    {
        $org = Organization::factory()->create();

        config([
            'ai.default' => 'openai',
            'ai.providers.openai.key' => null,
        ]);

        $result = $this->readiness->check($org);

        $this->assertFalse($result->ready);
        $this->assertSame('not_configured', $result->reason);
        $this->assertSame('openai', $result->provider);
        $this->assertNull($result->model);
        $this->assertFalse($result->usesByoKey);
    }

    public function test_ready_when_no_ai_settings_but_default_provider_has_platform_key(): void
    {
        $org = Organization::factory()->create();

        config([
            'ai.default' => 'openai',
            'ai.providers.openai.key' => 'sk-platform-default-key',
        ]);

        $result = $this->readiness->check($org);

        $this->assertTrue($result->ready);
        $this->assertNull($result->reason);
        $this->assertSame('openai', $result->provider);
        $this->assertNull($result->model);
        $this->assertFalse($result->usesByoKey);
    }

    public function test_ready_when_no_ai_settings_and_default_provider_is_ollama(): void
    {
        $org = Organization::factory()->create();

        config([
            'ai.default' => 'ollama',
            'ai.providers.ollama.key' => '',
        ]);

        $result = $this->readiness->check($org);

        $this->assertTrue($result->ready);
        $this->assertNull($result->reason);
        $this->assertSame('ollama', $result->provider);
        $this->assertNull($result->model);
        $this->assertFalse($result->usesByoKey);
    }

    public function test_reason_missing_key_when_selected_provider_lacks_byo_and_platform_key(): void
    {
        $org = Organization::factory()->create();

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'byo_key' => null,
            'data_sharing_ack_at' => now(),
        ]);

        config([
            'ai.providers.anthropic.key' => null,
        ]);

        $result = $this->readiness->check($org);

        $this->assertFalse($result->ready);
        $this->assertSame('missing_key', $result->reason);
        $this->assertSame('anthropic', $result->provider);
        $this->assertSame('claude-sonnet-5-5', $result->model);
        $this->assertFalse($result->usesByoKey);
    }

    public function test_reason_data_sharing_not_acknowledged_when_setting_exists_with_key_but_ack_is_null(): void
    {
        $org = Organization::factory()->create();

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'byo_key' => null,
            'data_sharing_ack_at' => null,
        ]);

        config([
            'ai.providers.anthropic.key' => 'sk-platform-key-123',
        ]);

        $result = $this->readiness->check($org);

        $this->assertFalse($result->ready);
        $this->assertSame('data_sharing_not_acknowledged', $result->reason);
        $this->assertSame('anthropic', $result->provider);
        $this->assertSame('claude-sonnet-5-5', $result->model);
        $this->assertFalse($result->usesByoKey);
    }

    public function test_ready_when_ai_settings_configured_with_byo_key_and_acknowledged(): void
    {
        $org = Organization::factory()->create();

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'byo_key' => 'sk-secret-byo-key-456',
            'data_sharing_ack_at' => now(),
        ]);

        config([
            'ai.providers.anthropic.key' => null,
        ]);

        $result = $this->readiness->check($org);

        $this->assertTrue($result->ready);
        $this->assertNull($result->reason);
        $this->assertSame('anthropic', $result->provider);
        $this->assertSame('claude-sonnet-5-5', $result->model);
        $this->assertTrue($result->usesByoKey);
    }

    public function test_ready_when_ai_settings_configured_with_platform_key_and_acknowledged(): void
    {
        $org = Organization::factory()->create();

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'byo_key' => null,
            'data_sharing_ack_at' => now(),
        ]);

        config([
            'ai.providers.deepseek.key' => 'sk-deepseek-platform',
        ]);

        $result = $this->readiness->check($org);

        $this->assertTrue($result->ready);
        $this->assertNull($result->reason);
        $this->assertSame('deepseek', $result->provider);
        $this->assertSame('deepseek-chat', $result->model);
        $this->assertFalse($result->usesByoKey);
    }

    public function test_ready_when_ai_settings_configured_with_ollama_and_acknowledged(): void
    {
        $org = Organization::factory()->create();

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => 'ollama',
            'model' => 'llama3.2',
            'byo_key' => null,
            'data_sharing_ack_at' => now(),
        ]);

        config([
            'ai.providers.ollama.key' => '',
        ]);

        $result = $this->readiness->check($org);

        $this->assertTrue($result->ready);
        $this->assertNull($result->reason);
        $this->assertSame('ollama', $result->provider);
        $this->assertSame('llama3.2', $result->model);
        $this->assertFalse($result->usesByoKey);
    }

    public function test_result_never_includes_or_exposes_the_api_key(): void
    {
        $org = Organization::factory()->create();
        $secret = 'sk-extremely-secret-key-never-leak';

        AiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'byo_key' => $secret,
            'data_sharing_ack_at' => now(),
        ]);

        $result = $this->readiness->check($org);

        $reflection = new ReflectionClass($result);
        foreach ($reflection->getProperties() as $prop) {
            $this->assertNotSame($secret, $prop->getValue($result));
            $this->assertNotSame('byo_key', $prop->getName());
            $this->assertNotSame('key', $prop->getName());
        }

        $this->assertStringNotContainsString($secret, json_encode($result) ?: '');
    }
}
