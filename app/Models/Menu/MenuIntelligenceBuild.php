<?php

declare(strict_types=1);

namespace App\Models\Menu;

use App\Domain\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Completion marker of one period's menu intelligence build; present with zero stats rows when the period had no sales.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $period_key
 * @property string $build_id
 * @property array<string, int> $rows step => rows written
 * @property Carbon $completed_at
 */
final class MenuIntelligenceBuild extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $table = 'menu_intelligence_builds';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'rows' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
