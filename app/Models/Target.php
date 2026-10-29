<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Targets\TargetObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([TargetObserver::class])]
class Target extends Model
{
    protected $table = 'targets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'value' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<TargetDaily, $this> */
    public function dailyTargets(): HasMany
    {
        return $this->hasMany(TargetDaily::class, 'target_id');
    }
}
