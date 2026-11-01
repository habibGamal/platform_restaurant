<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics\QueryMetrics;

final class MetricImplementation
{
    /**
     * @param  literal-string  $table
     * @param  literal-string  $sqlExpression
     * @param  literal-string|null  $numeratorExpression
     * @param  literal-string|null  $denominatorExpression
     */
    public function __construct(
        public readonly string $table,
        public readonly string $sqlExpression,
        public readonly bool $isRatio = false,
        public readonly ?string $numeratorExpression = null,
        public readonly ?string $denominatorExpression = null,
    ) {}
}
