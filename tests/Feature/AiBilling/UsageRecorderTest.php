<?php

declare(strict_types=1);

namespace Tests\Feature\AiBilling;

use App\Domain\AiBilling\UsageRecorder;
use App\Models\AiModelPrice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\TextUsage;
use Tests\TestCase;

class UsageRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_ledger_entry_with_cost_calculation(): void
    {
        AiModelPrice::factory()->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'input_per_1m' => 2.000000,
            'output_per_1m' => 10.000000,
            'cache_read_per_1m' => 0.200000,
            'cache_write_per_1m' => 2.500000,
            'effective_from' => '2026-01-01',
        ]);

        $organization = Organization::factory()->create();
        $user = User::factory()->forOrganization($organization)->create();

        $recorder = new UsageRecorder;

        // 10,000 total input (8,000 uncached, 2,000 cache read)
        // 1,000 output
        // costUsd: (8000*2 + 2000*0.2 + 1000*10) / 1M = (16000 + 400 + 10000) / 1M = 26400 / 1M = 0.026400
        // costEgp: 0.0264 * 50 = 1.3200
        $usage = new TextUsage(
            inputTokens: 10000,
            outputTokens: 1000,
            cacheReadInputTokens: 2000,
            cacheWriteInputTokens: 0,
            reasoningTokens: 100
        );

        $entry = $recorder->record(
            organization: $organization,
            user: $user,
            channel: 'chat',
            provider: 'anthropic',
            model: 'claude-sonnet-5-5',
            step: 2,
            usage: $usage,
            latencyMs: 780,
            conversationId: 'conv-123',
            messageId: 'msg-456'
        );

        $this->assertDatabaseHas('ai_usage_ledger', [
            'id' => $entry->id,
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'channel' => 'chat',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'step' => 2,
            'input_tokens' => 10000,
            'cache_read_tokens' => 2000,
            'cache_write_tokens' => 0,
            'output_tokens' => 1000,
            'reasoning_tokens' => 100,
            'cost_usd' => '0.026400',
            'cost_egp' => '1.3200',
            'latency_ms' => 780,
            'conversation_id' => 'conv-123',
            'message_id' => 'msg-456',
        ]);

        $this->assertNotNull($entry->created_at);
        $this->assertArrayNotHasKey('updated_at', $entry->getAttributes());
    }

    public function test_it_supports_integer_organization_id_and_null_user(): void
    {
        $organization = Organization::factory()->create();

        $recorder = new UsageRecorder;
        $usage = new TextUsage(inputTokens: 500, outputTokens: 100);

        $entry = $recorder->record(
            organization: $organization->id,
            user: null,
            channel: 'scheduled',
            provider: 'anthropic',
            model: 'claude-haiku-5-5',
            step: 1,
            usage: $usage
        );

        $this->assertSame($organization->id, $entry->organization_id);
        $this->assertNull($entry->user_id);
        $this->assertSame('scheduled', $entry->channel);
    }
}
