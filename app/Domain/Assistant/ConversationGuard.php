<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Models\AiConversationMeta;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;

final class ConversationGuard implements VerifiesConversationOwnership
{
    /**
     * Determine whether the conversation belongs to the given user and their organization.
     */
    public function belongsTo(string $conversationId, User $user): bool
    {
        if ($user->organization_id === null) {
            return false;
        }

        return AiConversationMeta::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->exists();
    }

    /**
     * Assert that the conversation belongs to the user and their organization, throwing if unauthorized.
     *
     * @throws AuthorizationException
     */
    public function assertBelongsTo(string $conversationId, User $user): void
    {
        if (! $this->belongsTo($conversationId, $user)) {
            throw new AuthorizationException('This assistant conversation does not belong to the user or organization.');
        }
    }

    /**
     * Register a new conversation metadata entry for the user and organization.
     *
     * @throws AuthorizationException
     */
    public function register(string $conversationId, User $user, Channel $channel, ?string $title = null): AiConversationMeta
    {
        if ($user->organization_id === null) {
            throw new AuthorizationException('User must belong to an organization to register an assistant conversation.');
        }

        return AiConversationMeta::create([
            'conversation_id' => $conversationId,
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'channel' => $channel,
            'title' => $title,
            'pinned' => false,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Determine whether the given conversation was stored for the given participant (SDK contract).
     */
    public function conversationBelongsTo(string $conversationId, ?string $participantType, string|int|null $participantId): bool
    {
        if ($participantId === null) {
            return false;
        }

        if ($participantType !== null && ! is_a($participantType, User::class, true) && $participantType !== (new User)->getMorphClass()) {
            return false;
        }

        $user = User::find($participantId);

        if (! $user instanceof User) {
            return false;
        }

        return $this->belongsTo($conversationId, $user);
    }
}
