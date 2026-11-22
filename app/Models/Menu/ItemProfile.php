<?php

declare(strict_types=1);

namespace App\Models\Menu;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The "Item Card": what an item is, who it serves and what it costs.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $item_key
 * @property string $name
 * @property string|null $category_key
 * @property string|null $role ItemRole value, trusted roles only
 * @property int $serves_n
 * @property string $serves_source recipe|category|owner|default
 * @property string $price_tier PriceTier value
 * @property string $price_tier_source auto|owner
 * @property string $season Season value
 * @property string $season_source auto|owner
 * @property float $list_price
 * @property float $unit_cost
 * @property array<string, float> $contains FoodClass value => summed qty, food classes only
 * @property string|null $main_protein FoodClass value
 * @property bool $has_recipe
 * @property bool $is_existing_combo
 * @property list<string>|null $combo_members
 * @property Carbon $built_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ItemProfile extends Model
{
    use BelongsToOrganization;

    protected $table = 'item_profiles';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'serves_n' => 'integer',
            'list_price' => 'float',
            'unit_cost' => 'float',
            'contains' => 'array',
            'has_recipe' => 'boolean',
            'is_existing_combo' => 'boolean',
            'combo_members' => 'array',
            'built_at' => 'datetime',
        ];
    }
}
