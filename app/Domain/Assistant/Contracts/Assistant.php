<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantReply;
use App\Domain\Assistant\Channel;
use App\Models\User;
use Generator;

/**
 * Entry point for every assistant channel (Filament chat, WhatsApp, scheduled jobs).
 *
 * Implementations run the access, budget and rate-limit gates, verify that
 * $conversationId belongs to $user, and start a new conversation when it is null.
 */
interface Assistant
{
    /**
     * Stream an answer. Yields AssistantEvent items; the last one is always
     * a `done` or `error` event carrying the conversation id.
     *
     * @return Generator<int, AssistantEvent>
     */
    public function stream(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): Generator;

    /**
     * Run the gates now and answer in a queued job that writes its events to the RunLog.
     *
     * Returns the conversation id (started now when $conversationId is null). A gate failure is
     * appended to the RunLog as an `error` event and nothing is queued.
     */
    public function start(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): ?string;

    /**
     * Answer without streaming (WhatsApp, scheduled jobs).
     */
    public function ask(User $user, Channel $channel, ?string $conversationId, string $text): AssistantReply;

    /**
     * Ask a running stream to stop after its current step.
     */
    public function cancel(string $runId): void;
}
