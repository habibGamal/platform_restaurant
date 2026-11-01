<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics\QueryMetrics;

final class DimensionDefinition
{
    /**
     * @param  literal-string  $name
     * @param  array<literal-string, literal-string>  $tableColumns  Map of table => SQL column name or expression
     * @param  literal-string  $outputKey
     */
    public function __construct(
        public readonly string $name,
        public readonly array $tableColumns,
        public readonly string $outputKey,
        public readonly ?string $label = null,
    ) {}

    public function supportsTable(string $table): bool
    {
        return isset($this->tableColumns[$table]);
    }

    /**
     * @return literal-string|null
     */
    public function getExpression(string $table): ?string
    {
        return $this->tableColumns[$table] ?? null;
    }

    /**
     * @return list<string>
     */
    public function candidateTables(): array
    {
        return array_keys($this->tableColumns);
    }
}
