<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Assistant\Channel;
use Database\Factories\AiConversationMetaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Ai\Models\Conversation;

/**
 * @property int $id
 * @property string $conversation_id
 * @property int $organization_id
 * @property int $user_id
 * @property Channel $channel
 * @property string|null $title
 * @property bool $pinned
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User $user
 * @property-read Conversation|null $conversation
 */
class AiConversationMeta extends Model
{
    /** @use HasFactory<AiConversationMetaFactory> */
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'ai_conversations_meta';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'pinned' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id',
        'organization_id',
        'user_id',
        'channel',
        'title',
        'pinned',
        'last_activity_at',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'pinned' => 'boolean',
            'last_activity_at' => 'datetime',
            'archived_at' => 'datetime',
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

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id', 'id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePinned(Builder $query): Builder
    {
        return $query->where('pinned', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }
}
