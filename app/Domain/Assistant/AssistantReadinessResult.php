<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

final readonly class AssistantReadinessResult
{
    public function __construct(
        public bool $ready,
        public ?string $reason,
        public string $provider,
        public ?string $model,
        public bool $usesByoKey,
    ) {}
}
