<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Page;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\AssistantReply;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Contracts\Assistant;
use App\Domain\Assistant\RunLog;
use App\Models\User;
use Generator;

final class FakeAssistant implements Assistant
{
    /**
     * @var list<array{user: User, channel: Channel, conversationId: ?string, text: string, runId: string}>
     */
    public array $streamCalls = [];

    /**
     * @var list<array{user: User, channel: Channel, conversationId: ?string, text: string, runId: string}>
     */
    public array $startCalls = [];

    /**
     * @var list<array{user: User, channel: Channel, conversationId: ?string, text: string}>
     */
    public array $askCalls = [];

    /**
     * @var list<string>
     */
    public array $cancelledRunIds = [];

    /**
     * @var list<AssistantEvent>
     */
    public array $eventsToStream = [];

    /**
     * @param  list<AssistantEvent>  $eventsToStream
     */
    public function __construct(array $eventsToStream = [])
    {
        $this->eventsToStream = $eventsToStream;
    }

    /**
     * @return Generator<int, AssistantEvent>
     */
    public function stream(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): Generator
    {
        $this->streamCalls[] = [
            'user' => $user,
            'channel' => $channel,
            'conversationId' => $conversationId,
            'text' => $text,
            'runId' => $runId,
        ];

        foreach ($this->eventsToStream as $event) {
            yield $event;
        }
    }

    /**
     * Records the call and writes the scripted events to the run log, as the queued job would.
     */
    public function start(User $user, Channel $channel, ?string $conversationId, string $text, string $runId): ?string
    {
        $this->startCalls[] = [
            'user' => $user,
            'channel' => $channel,
            'conversationId' => $conversationId,
            'text' => $text,
            'runId' => $runId,
        ];

        $log = new RunLog;
        foreach ($this->eventsToStream as $event) {
            $log->append($runId, $user->id, $event, $conversationId);
        }
        $log->flush($runId);

        return $conversationId;
    }

    public function ask(User $user, Channel $channel, ?string $conversationId, string $text): AssistantReply
    {
        $this->askCalls[] = [
            'user' => $user,
            'channel' => $channel,
            'conversationId' => $conversationId,
            'text' => $text,
        ];

        return new AssistantReply(text: 'Fake non-stream response', conversationId: $conversationId);
    }

    public function cancel(string $runId): void
    {
        $this->cancelledRunIds[] = $runId;
    }
}
