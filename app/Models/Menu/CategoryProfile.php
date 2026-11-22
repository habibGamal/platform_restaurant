<?php

declare(strict_types=1);

namespace App\Models\Menu;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Season and meal type of a menu category.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $category_key normalized via ItemKey::normalizeCategory()
 * @property string $name
 * @property string $season Season value
 * @property string $meal_type MealType value
 * @property string $source owner|auto|rule
 * @property float $confidence
 * @property Carbon $classified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class CategoryProfile extends Model
{
    use BelongsToOrganization;

    protected $table = 'category_profiles';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'confidence' => 'float',
            'classified_at' => 'datetime',
        ];
    }
}
