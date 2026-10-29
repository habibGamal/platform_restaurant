<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\DimProduct;

class ProductHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'product';
    }

    protected function modelClass(): string
    {
        return DimProduct::class;
    }

    protected function hasBusinessDate(): bool
    {
        return false;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        return [
            'name' => (string) $data['name'],
            'product_ref' => $data['product_ref'] ?? null,
            'category_uuid' => $data['category_uuid'] ?? null,
            'type' => $data['type'] ?? null,
            'unit' => $data['unit'] ?? null,
            'price' => (string) ($data['price'] ?? '0.00'),
            'cost' => (string) ($data['cost'] ?? '0.00'),
            'legacy' => (bool) ($data['legacy'] ?? false),
        ];
    }
}
