<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\DataHealthReport;

use Carbon\CarbonImmutable;

final readonly class RejectionLogRow
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public string $docType,
        public ?string $docUuid,
        public string $reason,
        public ?string $posVersion,
        public ?CarbonImmutable $firstSeenAt,
        public ?CarbonImmutable $lastSeenAt,
        public int $count,
    ) {}
}
