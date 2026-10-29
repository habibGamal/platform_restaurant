<?php

namespace Database\Factories;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes) {
                if (isset($attributes['restaurant_id'])) {
                    $restaurant = Restaurant::find($attributes['restaurant_id']);
                    if ($restaurant instanceof Restaurant) {
                        return $restaurant->organization_id;
                    }
                }

                return Organization::factory();
            },
            'restaurant_id' => fn (array $attributes) => Restaurant::factory()->create([
                'organization_id' => $attributes['organization_id'] ?? Organization::factory(),
            ])->id,
            'name' => fake()->streetName(),
            'timezone' => 'Africa/Cairo',
            'opened_on' => fake()->optional()->date(),
            'status' => BranchStatus::PENDING,
            'pos_version' => '1.0.0',
            'schema_version' => 1,
            'last_seen_at' => null,
            'last_batch_at' => null,
            'last_heartbeat_at' => null,
            'last_seq' => null,
            'pending_count' => 0,
            'failed_count' => 0,
            'oldest_pending_at' => null,
            'branch_secret' => Str::random(40),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => BranchStatus::ACTIVE,
        ]);
    }
}
