<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics\QueryMetrics;

final class MetricDefinition
{
    /**
     * @param  literal-string  $name
     * @param  array<string, MetricImplementation>  $implementations  Map of table => MetricImplementation
     * @param  literal-string|null  $kpiCode
     */
    public function __construct(
        public readonly string $name,
        public readonly array $implementations,
        public readonly ?string $kpiCode = null,
        public readonly ?string $label = null,
    ) {}

    public function supportsTable(string $table): bool
    {
        return isset($this->implementations[$table]);
    }

    public function getImplementation(string $table): ?MetricImplementation
    {
        return $this->implementations[$table] ?? null;
    }

    /**
     * @return list<string>
     */
    public function candidateTables(): array
    {
        return array_keys($this->implementations);
    }
}
