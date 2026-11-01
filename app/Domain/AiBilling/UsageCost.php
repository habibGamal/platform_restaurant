<?php

declare(strict_types=1);

namespace App\Domain\AiBilling;

final readonly class UsageCost
{
    public function __construct(
        public float $costUsd,
        public float $costEgp,
        public bool $priced,
    ) {}
}
