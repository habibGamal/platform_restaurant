<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchPairingCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchPairingCode>
 */
class BranchPairingCodeFactory extends Factory
{
    protected $model = BranchPairingCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code_hash' => hash('sha256', fake()->bothify('????????')),
            'expires_at' => now()->addMinutes(15),
            'used_at' => null,
            'created_by' => null,
        ];
    }
}
