<?php

declare(strict_types=1);

namespace App\Models\Menu;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A priced combo proposal built from a template around a hero item.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $period_key
 * @property string $template ComboTemplate value
 * @property string $channel
 * @property string $hero_key
 * @property string $signature
 * @property list<array{key: string, name: string, role: string, qty: int, list_price: float}> $items
 * @property float $list_total
 * @property float $cost_total
 * @property float $suggested_price
 * @property float $margin_pct
 * @property float $discount_pct
 * @property array<array-key, mixed> $evidence
 * @property array<array-key, mixed> $checks
 * @property string|null $overlaps_combo_key
 * @property float $score
 * @property string $status candidate|approved|rejected
 * @property string|null $owner_note
 * @property string $build_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ComboCandidate extends Model
{
    use BelongsToOrganization;

    protected $table = 'combo_candidates';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'items' => 'array',
            'list_total' => 'float',
            'cost_total' => 'float',
            'suggested_price' => 'float',
            'margin_pct' => 'float',
            'discount_pct' => 'float',
            'evidence' => 'array',
            'checks' => 'array',
            'score' => 'float',
        ];
    }
}
