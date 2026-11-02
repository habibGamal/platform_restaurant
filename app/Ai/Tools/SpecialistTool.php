<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Closure;
use Generator;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\Request;
use Throwable;

/** Observe child events before the SDK replaces them with preliminary parent results. */
final class SpecialistTool extends AgentTool
{
    /** @param Closure(string, StreamEvent): void $observer */
    public function __construct(Agent $agent, private readonly Closure $observer)
    {
        parent::__construct($agent);
    }

    public function stream(Request $request): Generator
    {
        $events = parent::stream($request);
        foreach ($events as $event) {
            try {
                ($this->observer)($this->name(), $event);
            } catch (Throwable $exception) {
                // Progress must never turn a successful specialist into a failed tool.
                report($exception);
            }

            yield $event;
        }

        return $events->getReturn();
    }
}
