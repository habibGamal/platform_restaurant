<?php

declare(strict_types=1);

namespace App\Ai\Gateway;

use Generator;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

/**
 * Chat Completions gateway that never asks the provider to stream.
 *
 * Some OpenAI-compatible proxies (miraiapi with Claude models) drop or garble a tool call that follows
 * text in a streamed reply, so the agent either stops after its plan or prints the call as text.
 * Each step is fetched whole and replayed as the same events a stream would produce, so the agent
 * loop, tools and progress events behave exactly as before; only the answer text arrives per step.
 */
class BufferedChatCompletionsGateway extends OpenAiCompatibleGateway
{
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $response = $this->generateTextStep($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout, $stepContext);

        yield from $this->replay($invocationId, $provider, $model, $response);

        return $response;
    }

    /**
     * The events a streamed step yields before returning its response: start, the text, then each tool call.
     *
     * @return Generator<int, StreamStart|TextStart|TextDelta|TextEnd|ToolCallEvent>
     */
    private function replay(string $invocationId, TextProvider $provider, string $model, StepResponse $response): Generator
    {
        yield (new StreamStart($this->generateEventId(), $provider->name(), $model, time()))->withInvocationId($invocationId);

        if (filled($response->text)) {
            $messageId = $this->generateEventId();

            yield (new TextStart($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
            yield (new TextDelta($this->generateEventId(), $messageId, $response->text, time()))->withInvocationId($invocationId);
            yield (new TextEnd($this->generateEventId(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($response->toolCalls as $toolCall) {
            yield (new ToolCallEvent($this->generateEventId(), $toolCall, time()))->withInvocationId($invocationId);
        }
    }
}
