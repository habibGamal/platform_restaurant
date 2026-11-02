<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $category_key normalized via ItemKey::normalizeCategory()
 * @property string|null $role null = mixed category
 * @property string $confidence
 * @property string $source owner|signal|auto
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 */
final class CategoryRoleAssignment extends Model
{
    protected $table = 'category_roles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'category_key',
        'role',
        'confidence',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'confidence' => 'decimal:2',
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
