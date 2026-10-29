<?php

namespace App\Domain\Ingestion\Contracts;

use InvalidArgumentException;

/**
 * Resolves the handler for a document type. Bound in the container by the warehouse-handlers task
 * (App\Domain\Ingestion\Handlers\HandlerRegistry). The ingestion HTTP/job layer depends only on this interface.
 */
interface DocumentHandlerRegistry
{
    /** @throws InvalidArgumentException for an unknown type */
    public function for(string $type): DocumentHandler;

    /** @return list<string> every supported document type */
    public function types(): array;
}
