<?php

declare(strict_types=1);

namespace App\Models\Menu;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FoodClass label of a recipe component (raw material).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $component_key
 * @property string $name
 * @property string $class FoodClass value
 * @property string $source owner|auto|rule
 * @property float $confidence
 * @property Carbon $classified_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ComponentClass extends Model
{
    use BelongsToOrganization;

    protected $table = 'component_classes';

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
