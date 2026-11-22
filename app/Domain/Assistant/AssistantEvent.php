<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

/**
 * One event in a streamed assistant answer, independent of the SDK's own event classes.
 *
 * type:
 *  - text_delta  : $text holds the next chunk of the answer
 *  - tool_call   : $toolName is about to run (UI shows a status chip)
 *  - tool_result : $toolName finished
 *  - tool_output : $toolName result captured for generative UI ($toolCallId, $input, $output, $agent)
 *  - done        : answer complete; $conversationId and $messageId are set
 *  - error       : $errorCode is set ('forbidden', 'budget_exceeded', 'rate_limited',
 *                  'cancelled', 'provider_error'), $text holds an Arabic message for the user
 */
final readonly class AssistantEvent
{
    /**
     * @param  array<string, mixed>|null  $input
     */
    public function __construct(
        public string $type,
        public ?string $text = null,
        public ?string $toolName = null,
        public ?string $conversationId = null,
        public ?string $messageId = null,
        public ?string $errorCode = null,
        public ?string $agent = null,
        public ?bool $failed = null,
        public ?string $toolCallId = null,
        public ?array $input = null,
        public mixed $output = null,
    ) {}

    public static function textDelta(string $text): self
    {
        return new self('text_delta', text: $text);
    }

    public static function toolCall(string $toolName): self
    {
        return new self('tool_call', toolName: $toolName);
    }

    public static function toolResult(string $toolName): self
    {
        return new self('tool_result', toolName: $toolName);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function toolOutput(
        string $toolName,
        string $toolCallId,
        array $input,
        mixed $output,
        ?string $agent = null,
    ): self {
        return new self(
            'tool_output',
            toolName: $toolName,
            agent: $agent,
            toolCallId: $toolCallId,
            input: $input,
            output: $output,
        );
    }

    public static function plan(string $text): self
    {
        return new self('plan', text: $text);
    }

    public static function agentStarted(string $agent, string $labelAr): self
    {
        return new self('agent_started', text: $labelAr, agent: $agent);
    }

    public static function stepStarted(string $agent, string $labelAr): self
    {
        return new self('step_started', text: $labelAr, agent: $agent);
    }

    public static function stepFinished(string $agent, ?string $summaryAr): self
    {
        return new self('step_finished', text: $summaryAr, agent: $agent);
    }

    public static function agentFinished(string $agent, bool $failed): self
    {
        return new self('agent_finished', agent: $agent, failed: $failed);
    }

    public static function done(string $conversationId, ?string $messageId = null): self
    {
        return new self('done', conversationId: $conversationId, messageId: $messageId);
    }

    public static function error(string $errorCode, string $messageAr, ?string $conversationId = null): self
    {
        return new self('error', text: $messageAr, conversationId: $conversationId, errorCode: $errorCode);
    }
}
