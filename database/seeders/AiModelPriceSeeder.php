<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AiModelPrice;
use Illuminate\Database\Seeder;

class AiModelPriceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $prices = [
            [
                'provider' => 'anthropic',
                'model' => 'claude-opus-5-5',
                'input_per_1m' => 4.000000,
                'output_per_1m' => 20.000000,
                'cache_read_per_1m' => 0.200000,
                'cache_write_per_1m' => 5.000000,
                'currency' => 'USD',
                'effective_from' => '2026-10-01',
            ],
            [
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5',
                'input_per_1m' => 2.000000,
                'output_per_1m' => 10.000000,
                'cache_read_per_1m' => 0.200000,
                'cache_write_per_1m' => 2.500000,
                'currency' => 'USD',
                'effective_from' => '2026-10-01',
            ],
            [
                'provider' => 'anthropic',
                'model' => 'claude-haiku-5-5',
                'input_per_1m' => 0.100000,
                'output_per_1m' => 0.500000,
                'cache_read_per_1m' => 0.010000,
                'cache_write_per_1m' => 0.125000,
                'currency' => 'USD',
                'effective_from' => '2026-10-01',
            ],
        ];

        foreach ($prices as $price) {
            AiModelPrice::query()->updateOrCreate(
                [
                    'provider' => $price['provider'],
                    'model' => $price['model'],
                    'effective_from' => $price['effective_from'],
                ],
                $price
            );
        }
    }
}
