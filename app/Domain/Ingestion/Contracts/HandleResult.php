<?php

namespace App\Domain\Ingestion\Contracts;

final readonly class HandleResult
{
    /**
     * @param  list<string>  $dirtyDates  business dates (Y-m-d) whose aggregates must be recomputed
     * @param  bool  $dayClosed  true for inventory_day / day_summary documents (marks the day closed)
     */
    public function __construct(
        public bool $applied,
        public array $dirtyDates = [],
        public bool $dayClosed = false,
    ) {}

    /** @param  list<string>  $dirtyDates */
    public static function applied(array $dirtyDates = [], bool $dayClosed = false): self
    {
        return new self(true, array_values(array_unique(array_filter($dirtyDates))), $dayClosed);
    }

    public static function skipped(): self
    {
        return new self(false);
    }
}
