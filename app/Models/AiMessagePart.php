<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stored part (tool output or worklog snapshot) of a saved assistant message.
 *
 * @property int $id
 * @property string $message_id
 * @property string $conversation_id
 * @property int $user_id
 * @property int $position
 * @property string $kind
 * @property string|null $tool_name
 * @property string|null $tool_call_id
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 * @property-read User $user
 */
class AiMessagePart extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'message_id',
        'conversation_id',
        'user_id',
        'position',
        'kind',
        'tool_name',
        'tool_call_id',
        'payload',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'payload' => 'array',
        ];
    }
}
