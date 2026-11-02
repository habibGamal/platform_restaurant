<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $item_key
 * @property string $role
 * @property string $source owner|signal|auto
 * @property string $confidence
 * @property Carbon $classified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 */
final class ItemRoleAssignment extends Model
{
    protected $table = 'item_roles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'item_key',
        'role',
        'source',
        'confidence',
        'classified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'confidence' => 'decimal:2',
            'classified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
