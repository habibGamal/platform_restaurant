<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiModelCertification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModelCertification>
 */
class AiModelCertificationFactory extends Factory
{
    protected $model = AiModelCertification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'eval_version' => 'v0',
            'scores' => [
                'grounding' => 0.99,
                'permissions' => 1.0,
                'tool_selection' => 0.95,
                'judge' => 4.5,
            ],
            'passed' => true,
            'run_cost_usd' => 0.420000,
            'certified_at' => now(),
        ];
    }
}
