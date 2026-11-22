<?php

declare(strict_types=1);

namespace App\Models\Menu;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An item's place in a strategy's ranked list, within its segment.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $period_key
 * @property string $strategy
 * @property string $channel
 * @property string $segment_key <role>:<price_tier>
 * @property string $item_key
 * @property float $score
 * @property int $rank 1-based within strategy + channel + segment
 * @property string $quadrant
 * @property array<string, array{value: float, weight: float, contribution: float}> $breakdown
 * @property string $build_id
 * @property Carbon|null $created_at
 */
final class ItemRanking extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $table = 'item_rankings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'score' => 'float',
            'rank' => 'integer',
            'breakdown' => 'array',
        ];
    }
}
