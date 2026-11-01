<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\PendingStep;

final class StopOnCancel
{
    public function __construct(
        private readonly User|int $user,
        private readonly ?string $runId = null,
    ) {}

    public function handle(PendingStep $step, Closure $next): StepResult
    {
        $userId = $this->user instanceof User ? $this->user->id : $this->user;

        if ($this->runId !== null && Cache::has("ai:cancel:{$userId}:{$this->runId}")) {
            return StepResult::stop();
        }

        return $next($step);
    }
}
