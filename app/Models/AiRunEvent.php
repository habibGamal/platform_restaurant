<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One stored event of an assistant run, read back by the chat page while the run's job streams.
 *
 * @property int $id
 * @property string $run_id
 * @property int $user_id
 * @property string|null $conversation_id
 * @property string|null $message_id
 * @property int $seq
 * @property string $type
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 */
class AiRunEvent extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['run_id', 'user_id', 'conversation_id', 'message_id', 'seq', 'type', 'payload'];

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(30));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'payload' => 'array',
        ];
    }
}
