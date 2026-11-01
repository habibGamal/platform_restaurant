<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AiUsageLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string|null $conversation_id
 * @property string|null $message_id
 * @property string $channel
 * @property string $provider
 * @property string $model
 * @property int $step
 * @property int $input_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property int $output_tokens
 * @property int $reasoning_tokens
 * @property string $cost_usd
 * @property string $cost_egp
 * @property int|null $latency_ms
 * @property Carbon|null $created_at
 * @property-read Organization $organization
 * @property-read User|null $user
 */
class AiUsageLedgerEntry extends Model
{
    /** @use HasFactory<AiUsageLedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ai_usage_ledger';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'conversation_id',
        'message_id',
        'channel',
        'provider',
        'model',
        'step',
        'input_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'output_tokens',
        'reasoning_tokens',
        'cost_usd',
        'cost_egp',
        'latency_ms',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'user_id' => 'integer',
            'step' => 'integer',
            'input_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'cost_egp' => 'decimal:4',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
