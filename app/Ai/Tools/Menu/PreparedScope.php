<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ReportFilters;

/**
 * The resolved scope of a prepared-menu tool call: the period keys (chronological), their inclusive
 * date bounds, the user's metrics scope (all of the organization's branches) and when the data was built.
 */
final readonly class PreparedScope
{
    /**
     * @param  list<string>  $periodKeys
     * @param  array<string, array{from: string, to: string}>  $ranges  period key => bounds
     * @param  array<int, string>  $branchMap
     */
    public function __construct(
        public int $organizationId,
        public array $periodKeys,
        public array $ranges,
        public ReportFilters $filters,
        public MetricsQuery $query,
        public array $branchMap,
        public ?string $preparedAt = null,
    ) {}

    public function withPreparedAt(?string $preparedAt): self
    {
        return new self($this->organizationId, $this->periodKeys, $this->ranges, $this->filters, $this->query, $this->branchMap, $preparedAt);
    }

    /** The latest requested period (single-period reads such as itemsets); keys are chronological. */
    public function latestPeriod(): string
    {
        return $this->periodKeys[count($this->periodKeys) - 1];
    }
}
