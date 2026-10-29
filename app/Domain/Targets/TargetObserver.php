<?php

declare(strict_types=1);

namespace App\Domain\Targets;

use App\Models\Target;

final class TargetObserver
{
    /**
     * Handle the Target "saved" event.
     */
    public function saved(Target $target): void
    {
        TargetDistributor::persist($target);
    }
}
