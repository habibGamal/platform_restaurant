<?php

declare(strict_types=1);

namespace App\Models\Menu;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-period item stats. branch_id 0 = all branches, channel 'all' = all channels.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $period_key
 * @property int $branch_id
 * @property string $channel
 * @property string $item_key
 * @property float $qty
 * @property float $net_sales
 * @property float $cogs
 * @property float $gross_profit
 * @property int $open_days
 * @property int $days_sold
 * @property float $velocity
 * @property int $orders
 * @property float|null $attach_rate
 * @property array<array-key, mixed> $daypart
 * @property array<array-key, mixed> $weekday
 * @property array<array-key, mixed>|null $channel_mix
 * @property float|null $repeat_customer_share
 * @property float $return_qty
 * @property float $waste_cost
 * @property float|null $mom_velocity_change
 * @property string $build_id
 * @property Carbon|null $created_at
 */
final class ItemPeriodStat extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $table = 'item_period_stats';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'branch_id' => 'integer',
            'qty' => 'float',
            'net_sales' => 'float',
            'cogs' => 'float',
            'gross_profit' => 'float',
            'open_days' => 'integer',
            'days_sold' => 'integer',
            'velocity' => 'float',
            'orders' => 'integer',
            'attach_rate' => 'float',
            'daypart' => 'array',
            'weekday' => 'array',
            'channel_mix' => 'array',
            'repeat_customer_share' => 'float',
            'return_qty' => 'float',
            'waste_cost' => 'float',
            'mom_velocity_change' => 'float',
        ];
    }
}
