<?php

namespace App\Models;

use App\Enums\OrganizationPlan;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'plan',
        'ai_enabled',
        'timezone',
        'currency',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'plan' => OrganizationPlan::class,
            'ai_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<Restaurant, $this>
     */
    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<BranchSet, $this>
     */
    public function branchSets(): HasMany
    {
        return $this->hasMany(BranchSet::class);
    }
}
