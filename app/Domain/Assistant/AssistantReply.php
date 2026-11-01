<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

/**
 * A complete, non-streamed assistant answer.
 */
final readonly class AssistantReply
{
    /**
     * @param  list<string>  $toolsUsed
     */
    public function __construct(
        public string $text,
        public ?string $conversationId,
        public ?string $messageId = null,
        public array $toolsUsed = [],
        public ?string $errorCode = null,
    ) {}

    public function failed(): bool
    {
        return $this->errorCode !== null;
    }
}
