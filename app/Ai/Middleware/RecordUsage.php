<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Domain\AiBilling\UsageRecorder;
use App\Domain\Assistant\Channel;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

final class RecordUsage
{
    /**
     * @param  string|(Closure(): (?string))|null  $conversationId
     */
    public function __construct(
        private readonly User $user,
        private readonly Channel $channel,
        private readonly mixed $conversationId = null,
        private readonly ?UsageRecorder $usageRecorder = null,
    ) {}

    public function handle(PendingStep $step, Closure $next): StepResult
    {
        $startTime = hrtime(true);

        $result = $next($step);

        return $result->then(function (StepResponse $response) use ($step, $startTime): void {
            $latencyMs = (int) round((hrtime(true) - $startTime) / 1e6);

            $recorder = $this->usageRecorder ?? resolve(UsageRecorder::class);

            $org = $this->user->organization ?? ($this->user->organization_id !== null ? Organization::find($this->user->organization_id) : null);
            if ($org === null) {
                return;
            }

            $cid = is_callable($this->conversationId)
                ? ($this->conversationId)()
                : (is_string($this->conversationId) ? $this->conversationId : null);

            $recorder->record(
                organization: $org,
                user: $this->user,
                channel: $this->channel,
                provider: $step->provider,
                model: $step->model,
                step: $step->number,
                usage: $response->usage,
                latencyMs: $latencyMs,
                conversationId: $cid,
            );
        });
    }
}
