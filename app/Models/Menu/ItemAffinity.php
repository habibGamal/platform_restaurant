<?php

declare(strict_types=1);

namespace App\Models\Menu;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A basket itemset: items bought together over one period and channel.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $period_key
 * @property string $channel
 * @property int $size
 * @property string $itemset_hash sha1 of the sorted item keys joined with '|'
 * @property list<string> $items
 * @property array<string, int> $member_orders item key => completed orders containing it
 * @property int $together_orders
 * @property int $total_orders
 * @property float $support
 * @property float $lift
 * @property float $avg_order_value
 * @property string $build_id
 * @property Carbon|null $created_at
 */
final class ItemAffinity extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $table = 'item_affinities';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'size' => 'integer',
            'items' => 'array',
            'member_orders' => 'array',
            'together_orders' => 'integer',
            'total_orders' => 'integer',
            'support' => 'float',
            'lift' => 'float',
            'avg_order_value' => 'float',
        ];
    }
}
