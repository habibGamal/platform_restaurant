<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\TargetsVsActualReport;

final readonly class TargetHistoryRow
{
    /**
     * @param  array<string, TargetHistoryCell>  $cells
     */
    public function __construct(
        public ?int $branchId,
        public string $branchName,
        public bool $isGroup,
        public array $cells,
    ) {}
}
