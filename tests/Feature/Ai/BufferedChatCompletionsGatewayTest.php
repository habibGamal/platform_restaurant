<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Gateway\BufferedChatCompletionsGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Tests\TestCase;

final class BufferedChatCompletionsGatewayTest extends TestCase
{
    public function test_a_provider_with_stream_false_uses_the_buffered_gateway_and_others_keep_streaming(): void
    {
        config([
            'ai.providers.buffered_probe' => ['driver' => 'openai-compatible', 'url' => 'https://proxy.test/v1', 'key' => 'k', 'stream' => false],
            'ai.providers.streaming_probe' => ['driver' => 'openai-compatible', 'url' => 'https://proxy.test/v1', 'key' => 'k'],
        ]);

        $this->assertInstanceOf(BufferedChatCompletionsGateway::class, Ai::textProvider('buffered_probe')->textGateway());
        $streaming = Ai::textProvider('streaming_probe')->textGateway();
        $this->assertInstanceOf(OpenAiCompatibleGateway::class, $streaming);
        $this->assertNotInstanceOf(BufferedChatCompletionsGateway::class, $streaming);
    }

    public function test_a_step_is_fetched_whole_and_replayed_as_stream_events(): void
    {
        config(['ai.providers.buffered_probe' => ['driver' => 'openai-compatible', 'url' => 'https://proxy.test/v1', 'key' => 'k', 'stream' => false]]);

        Http::fake(['proxy.test/*' => Http::response([
            'id' => 'chatcmpl-1',
            'model' => 'probe-model',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => 'خطة: أفوّض التحليل.',
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'marketing_specialist', 'arguments' => '{"task":"October menu"}'],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ])]);

        $provider = Ai::textProvider('buffered_probe');
        $gateway = new BufferedChatCompletionsGateway(app(Dispatcher::class));

        $stream = $gateway->generateStreamStep('inv-1', $provider, 'probe-model', 'You coordinate.', [], [], null, null, 60, new StepContext);
        $events = iterator_to_array($stream, false);
        $response = $stream->getReturn();

        $this->assertInstanceOf(StreamStart::class, $events[0]);
        $deltas = array_values(array_filter($events, fn ($e): bool => $e instanceof TextDelta));
        $this->assertCount(1, $deltas);
        $this->assertSame('خطة: أفوّض التحليل.', $deltas[0]->delta);

        $calls = array_values(array_filter($events, fn ($e): bool => $e instanceof ToolCall));
        $this->assertCount(1, $calls);
        $this->assertSame('marketing_specialist', $calls[0]->toolCall->name);
        $this->assertSame(['inv-1'], array_values(array_unique(array_map(fn ($e): ?string => $e->invocationId, $events))));

        $this->assertInstanceOf(StepResponse::class, $response);
        $this->assertCount(1, $response->toolCalls);

        // The provider must be asked for a whole reply, never a stream.
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/chat/completions') && ($request['stream'] ?? false) !== true);
    }
}
