<?php

namespace Database\Factories;

use App\Models\BranchSet;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchSet>
 */
class BranchSetFactory extends Factory
{
    protected $model = BranchSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => null,
            'name' => fake()->words(2, true),
        ];
    }
}
