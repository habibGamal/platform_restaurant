<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\DataHealthReport;

use Carbon\CarbonImmutable;

final readonly class BranchHealthRow
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public string $status,
        public ?CarbonImmutable $lastHeartbeatAt,
        public ?string $lastHeartbeatFormatted,
        public ?CarbonImmutable $lastBatchAt,
        public ?string $lastBatchFormatted,
        public ?string $posVersion,
        public bool $isOutdated,
        public ?int $schemaVersion,
        public int $pendingCount,
        public ?CarbonImmutable $oldestPendingAt,
        public ?string $oldestPendingAge,
        public int $failedCount,
        public int $rejectedDocs7Days,
    ) {}
}
