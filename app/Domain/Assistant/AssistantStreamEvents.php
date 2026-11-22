<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Ai\Tools\Contracts\ReportsProgress;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\ToolNameResolver;

/** Per-run mapping; no application-global listeners or cross-run state. */
final class AssistantStreamEvents
{
    private const AGENTS = [
        'marketing_specialist' => ['marketing', 'التسويق'],
        'finance_specialist' => ['finance', 'المالية'],
        'operations_specialist' => ['operations', 'التشغيل'],
        'data_checker' => ['data_checker', 'مدقق البيانات'],
    ];

    private ?string $coordinatorInvocation = null;

    /** @var array<string, string> */
    private array $invocations = [];

    /** @var array<string, array<string, Tool>> */
    private array $tools = [];

    /** @var array<string, array{name: string, input: array<string, mixed>, agent: ?string}> */
    private array $pendingToolCalls = [];

    private const PLAN_PREFIX = 'خطة:';

    /** Coordinator text not yet released: the start of a line that may still turn out to be a plan. */
    private string $buffer = '';

    private bool $atLineStart = true;

    /** Blank lines right after a plan line are dropped so the answer does not start with them. */
    private bool $afterPlan = false;

    /** Everything the coordinator wrote, for detecting tool calls printed as text. */
    private string $coordinatorText = '';

    /** @param iterable<object> $tools */
    public function __construct(iterable $tools, private readonly Channel $channel)
    {
        foreach ($tools as $tool) {
            if (! $tool instanceof Tool) {
                continue;
            }
            $name = ToolNameResolver::resolve($tool);
            $this->tools['assistant'][$name] = $tool;
            if ($tool instanceof AgentTool && $tool->agent() instanceof HasTools) {
                $agent = self::AGENTS[$name][0] ?? $name;
                foreach ($tool->agent()->tools() as $childTool) {
                    if ($childTool instanceof Tool) {
                        $this->tools[$agent][ToolNameResolver::resolve($childTool)] = $childTool;
                    }
                }
            }
        }
    }

    public function isCoordinator(StreamEvent $event): bool
    {
        return $this->coordinatorInvocation !== null && $event->invocationId === $this->coordinatorInvocation;
    }

    /** @return list<AssistantEvent> */
    public function map(StreamEvent $event, ?string $specialist = null): array
    {
        // The coordinator's invocation is fixed by its first event (normally StreamStart). A step stopped
        // before it starts (cancel, budget) only yields StreamEnd, which must still count as the coordinator's.
        if ($specialist !== null) {
            $specialistKey = self::AGENTS[$specialist][0] ?? $specialist;
            if ($event->invocationId !== null) {
                $this->invocations[$event->invocationId] ??= $specialistKey;
            }
            $agent = $this->invocations[$event->invocationId ?? ''] ?? $specialistKey;
        } else {
            if ($this->coordinatorInvocation === null && $event->invocationId !== null) {
                $this->coordinatorInvocation = $event->invocationId;
                $this->invocations[$event->invocationId] = 'assistant';
            }
            $agent = $this->invocations[$event->invocationId ?? ''] ?? ($this->coordinatorInvocation === null ? 'assistant' : null);
        }

        if ($agent === null) {
            return [];
        }

        if ($event instanceof TextDelta) {
            return $this->isCoordinator($event) ? $this->text($event->delta) : [];
        }
        if (($event instanceof TextEnd || $event instanceof StreamEnd) && $this->isCoordinator($event)) {
            return $this->flush();
        }
        if ($event instanceof ToolCall) {
            $events = $this->isCoordinator($event) ? $this->flush() : [];
            $name = $event->toolCall->name;
            $toolCallId = $event->toolCall->id !== '' ? $event->toolCall->id : $event->id;
            $this->pendingToolCalls[$toolCallId] = [
                'name' => $name,
                'input' => $event->toolCall->arguments,
                'agent' => $agent === 'assistant' ? null : $agent,
            ];
            $events[] = AssistantEvent::toolCall($name);
            if (isset(self::AGENTS[$name])) {
                [$key, $label] = self::AGENTS[$name];
                $events[] = AssistantEvent::agentStarted($key, $label);
            } else {
                $tool = $this->tools[$agent][$name] ?? null;
                $label = $tool instanceof ReportsProgress ? $tool->progressLabel() : 'براجع البيانات…';
                $events[] = AssistantEvent::stepStarted($agent, $label);
            }

            return $this->forChannel($events);
        }
        if ($event instanceof ToolResult && ! $event->preliminary) {
            $name = $event->toolResult->name;
            $events = [AssistantEvent::toolResult($name)];
            if (isset(self::AGENTS[$name])) {
                $failed = ! $event->successful || str_starts_with($event->toolResult->text(), 'Agent failed:');
                $events[] = AssistantEvent::agentFinished(self::AGENTS[$name][0], $failed);
            } else {
                $result = $event->toolResult->result;
                $decoded = is_string($result) ? json_decode($result, true) : $result;
                $summary = is_array($decoded) && is_string($decoded['summary'] ?? null) ? $decoded['summary'] : null;
                $events[] = AssistantEvent::stepFinished($agent, $summary);
            }

            if ($this->isUiTool($name)) {
                $toolCallId = $event->toolResult->id !== '' ? $event->toolResult->id : $event->id;
                $pending = $this->pendingToolCalls[$toolCallId] ?? $this->pendingToolCalls[$event->id] ?? null;
                unset($this->pendingToolCalls[$toolCallId], $this->pendingToolCalls[$event->id]);

                $input = $pending['input'] ?? $event->toolResult->arguments;
                $outputAgent = $pending['agent'] ?? ($agent === 'assistant' ? null : $agent);

                $output = $this->resolveToolOutput($event);

                $events[] = AssistantEvent::toolOutput($name, $toolCallId, $input, $output, $outputAgent);
            }

            return $this->forChannel($events);
        }

        return [];
    }

    /**
     * Stream coordinator text line by line: any line starting with «خطة:» becomes a `plan` event
     * wherever it appears; every other line passes through as soon as it cannot be a plan.
     *
     * @return list<AssistantEvent>
     */
    private function text(string $delta): array
    {
        $this->coordinatorText .= $delta;
        $this->buffer .= $delta;
        $events = [];

        while ($this->buffer !== '') {
            if (! $this->atLineStart) {
                $newline = strpos($this->buffer, "\n");
                if ($newline === false) {
                    $events[] = AssistantEvent::textDelta($this->buffer);
                    $this->buffer = '';

                    break;
                }
                $events[] = AssistantEvent::textDelta(substr($this->buffer, 0, $newline + 1));
                $this->buffer = substr($this->buffer, $newline + 1);
                $this->atLineStart = true;

                continue;
            }

            if ($this->afterPlan) {
                $this->buffer = ltrim($this->buffer, "\r\n");
                if ($this->buffer === '') {
                    break;
                }
            }

            $newline = strpos($this->buffer, "\n");
            $line = ltrim($newline === false ? $this->buffer : substr($this->buffer, 0, $newline), " \t");

            if (str_starts_with($line, self::PLAN_PREFIX)) {
                if ($newline === false) {
                    break; // Wait for the end of the plan line.
                }
                $events[] = $this->planEvent($line);
                $this->buffer = substr($this->buffer, $newline + 1);
                $this->afterPlan = true;

                continue;
            }

            if ($newline === false && str_starts_with(self::PLAN_PREFIX, $line)) {
                break; // Too short to tell yet.
            }

            $this->atLineStart = false;
            $this->afterPlan = false;
        }

        return $this->forChannel($events);
    }

    /**
     * Release whatever coordinator text is still held back (end of a text block or of the run).
     *
     * @return list<AssistantEvent>
     */
    public function flush(): array
    {
        $buffer = $this->buffer;
        $wasLineStart = $this->atLineStart;
        $this->buffer = '';
        $this->atLineStart = true;

        if ($wasLineStart && $this->afterPlan) {
            $buffer = ltrim($buffer, "\r\n");
        }
        if ($buffer === '') {
            return [];
        }

        $line = ltrim($buffer, " \t");
        if ($wasLineStart && str_starts_with($line, self::PLAN_PREFIX)) {
            $this->afterPlan = true;

            return $this->forChannel([$this->planEvent($line)]);
        }

        $this->afterPlan = false;

        return [AssistantEvent::textDelta($buffer)];
    }

    /**
     * Whether the coordinator printed a tool call as text instead of calling the tool.
     */
    public function coordinatorPrintedToolCall(): bool
    {
        return self::containsPrintedToolCall($this->coordinatorText);
    }

    public static function containsPrintedToolCall(string $text): bool
    {
        // OpenAI/Anthropic-style tags, Anthropic's legacy <function_calls>, and DeepSeek's ｜DSML｜ markup
        // (seen when an OpenAI-compatible proxy routes a request to a DeepSeek model).
        if (preg_match('/<\/?(tool_(call|use)|function_calls)\b|｜DSML｜/iu', $text) === 1) {
            return true;
        }

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if (! str_starts_with($line, '{') || ! str_ends_with($line, '}')) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && array_key_exists('name', $decoded) && array_key_exists('arguments', $decoded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The answer without its «خطة:» lines, for replies that are not streamed.
     */
    public static function withoutPlanLines(string $text): string
    {
        return preg_replace('/^[ \t]*'.preg_quote(self::PLAN_PREFIX, '/').'.*(?:\R+|$)/mu', '', $text) ?? $text;
    }

    private function planEvent(string $line): AssistantEvent
    {
        return AssistantEvent::plan(trim(substr($line, strlen(self::PLAN_PREFIX))));
    }

    private function isUiTool(string $name): bool
    {
        return in_array($name, (array) config('assistant.ui_tools', []), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveToolOutput(ToolResult $event): array
    {
        $isFailed = ! $event->successful || $event->error !== null || ! $event->toolResult->successful();

        if ($isFailed) {
            $errorMessage = $event->error
                ?? $event->toolResult->error()
                ?? (is_string($event->toolResult->result) && $event->toolResult->result !== '' ? $event->toolResult->result : null)
                ?? 'الأداة مقدرتش ترجّع نتيجة.';

            return ['error' => $errorMessage];
        }

        $raw = $event->toolResult->result;

        if (is_array($raw)) {
            /** @var array<string, mixed> $raw */
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }

            return ['raw' => $raw];
        }

        return ['raw' => (string) $raw];
    }

    /**
     * @param  list<AssistantEvent>  $events
     * @return list<AssistantEvent>
     */
    private function forChannel(array $events): array
    {
        return $this->channel === Channel::WhatsApp
            ? array_values(array_filter($events, fn (AssistantEvent $event): bool => in_array($event->type, ['text_delta', 'tool_call', 'tool_result'], true)))
            : $events;
    }
}
