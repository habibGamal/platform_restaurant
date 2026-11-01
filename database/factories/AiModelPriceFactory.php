<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiModelPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModelPrice>
 */
class AiModelPriceFactory extends Factory
{
    protected $model = AiModelPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'input_per_1m' => 2.000000,
            'output_per_1m' => 10.000000,
            'cache_read_per_1m' => 0.200000,
            'cache_write_per_1m' => 2.500000,
            'currency' => 'USD',
            'effective_from' => now()->startOfMonth()->toDateString(),
        ];
    }
}
