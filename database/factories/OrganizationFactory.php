<?php

namespace Database\Factories;

use App\Enums\OrganizationPlan;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 99999),
            'plan' => OrganizationPlan::BASIC,
            'ai_enabled' => false,
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
            'settings' => null,
        ];
    }

    public function pro(): static
    {
        return $this->state(fn () => [
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);
    }
}
