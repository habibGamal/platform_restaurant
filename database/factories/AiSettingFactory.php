<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiSetting;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiSetting>
 */
class AiSettingFactory extends Factory
{
    protected $model = AiSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'failover' => [
                ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5'],
            ],
            'reasoning_level' => 'medium',
            'byo_key' => null,
            'monthly_budget_egp' => 1500.00,
            'per_user_monthly_egp' => null,
            'warn_at' => 0.80,
            'hard_stop' => true,
            'data_sharing_ack_at' => null,
        ];
    }
}
