<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

final readonly class GroundingResult
{
    /**
     * @param  list<int|float>  $ungrounded
     * @param  list<int|float>  $matched
     */
    public function __construct(
        public bool $grounded,
        public array $ungrounded = [],
        public array $matched = [],
    ) {}
}
