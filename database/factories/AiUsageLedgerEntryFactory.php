<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiUsageLedgerEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageLedgerEntry>
 */
class AiUsageLedgerEntryFactory extends Factory
{
    protected $model = AiUsageLedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'conversation_id' => fake()->uuid(),
            'message_id' => fake()->uuid(),
            'channel' => 'chat',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'step' => 1,
            'input_tokens' => 1000,
            'cache_read_tokens' => 200,
            'cache_write_tokens' => 0,
            'output_tokens' => 500,
            'reasoning_tokens' => 0,
            'cost_usd' => 0.006640,
            'cost_egp' => 0.3320,
            'latency_ms' => 450,
            'created_at' => now(),
        ];
    }
}
