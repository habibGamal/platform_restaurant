<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $provider
 * @property string $model
 * @property array<int, array{provider: string, model: string}>|null $failover
 * @property string $reasoning_level
 * @property string|null $byo_key
 * @property string|null $monthly_budget_egp
 * @property string|null $per_user_monthly_egp
 * @property string $warn_at
 * @property bool $hard_stop
 * @property Carbon|null $data_sharing_ack_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 */
#[Hidden(['byo_key'])]
class AiSetting extends Model
{
    /** @use HasFactory<AiSettingFactory> */
    use HasFactory;

    protected $table = 'ai_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'provider',
        'model',
        'failover',
        'reasoning_level',
        'byo_key',
        'monthly_budget_egp',
        'per_user_monthly_egp',
        'warn_at',
        'hard_stop',
        'data_sharing_ack_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'byo_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'failover' => 'array',
            'byo_key' => 'encrypted',
            'monthly_budget_egp' => 'decimal:2',
            'per_user_monthly_egp' => 'decimal:2',
            'warn_at' => 'decimal:2',
            'hard_stop' => 'boolean',
            'data_sharing_ack_at' => 'datetime',
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
