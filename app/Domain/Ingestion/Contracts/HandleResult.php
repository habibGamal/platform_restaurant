<?php

namespace App\Domain\Ingestion\Contracts;

final readonly class HandleResult
{
    /**
     * @param  list<string>  $dirtyDates  business dates (Y-m-d) whose aggregates must be recomputed
     * @param  bool  $dayClosed  true for inventory_day / day_summary documents (marks the day closed)
     * @param  bool  $created  true when the document inserted a new parent row (rather than updating one)
     */
    public function __construct(
        public bool $applied,
        public array $dirtyDates = [],
        public bool $dayClosed = false,
        public bool $created = false,
    ) {}

    /** @param  list<string>  $dirtyDates */
    public static function applied(array $dirtyDates = [], bool $dayClosed = false, bool $created = false): self
    {
        return new self(true, array_values(array_unique(array_filter($dirtyDates))), $dayClosed, $created);
    }

    public static function skipped(): self
    {
        return new self(false);
    }
}
