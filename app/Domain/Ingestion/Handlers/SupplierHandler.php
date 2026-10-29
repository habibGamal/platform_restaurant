<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\DimSupplier;

class SupplierHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'supplier';
    }

    protected function modelClass(): string
    {
        return DimSupplier::class;
    }

    protected function hasBusinessDate(): bool
    {
        return false;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        return [
            'name' => (string) $data['name'],
            'phone' => $data['phone'] ?? null,
        ];
    }
}
