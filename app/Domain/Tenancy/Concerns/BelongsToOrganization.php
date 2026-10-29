<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Scopes\OrganizationScope;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model): void {
            if (empty($model->organization_id) && auth()->check() && auth()->user()?->organization_id !== null) {
                $model->organization_id = auth()->user()->organization_id;
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
