<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\WhPurchase;
use App\Models\Warehouse\WhPurchaseItem;
use Illuminate\Database\Eloquent\Model;

class PurchaseHandler extends AbstractDocumentHandler
{
    public function __construct(
        private readonly string $type = 'purchase_invoice',
    ) {}

    public function type(): string
    {
        return $this->type;
    }

    protected function modelClass(): string
    {
        return WhPurchase::class;
    }

    protected function kind(): string
    {
        return $this->type === 'purchase_invoice' ? 'invoice' : 'return';
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        return [
            'business_date' => $data['business_date'] ?? null,
            'kind' => $this->kind(),
            'closed_at' => $this->parseTimestamp($data['closed_at'] ?? null),
            'supplier_uuid' => $data['supplier_uuid'] ?? null,
            'total' => (string) ($data['total'] ?? '0.00'),
        ];
    }

    protected function replaceChildren(Branch $branch, Model $parent, array $data, array $document): void
    {
        WhPurchaseItem::where('branch_id', $branch->id)
            ->where('purchase_uuid', $parent->getAttribute('uuid'))
            ->delete();

        $items = (array) ($data['items'] ?? []);
        if (! empty($items)) {
            $purchaseItems = [];
            foreach ($items as $item) {
                $purchaseItems[] = [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'purchase_uuid' => $parent->getAttribute('uuid'),
                    'product_uuid' => $item['product_uuid'] ?? null,
                    'quantity' => (string) ($item['quantity'] ?? '0.000'),
                    'price' => (string) ($item['price'] ?? '0.00'),
                    'total' => (string) ($item['total'] ?? '0.00'),
                ];
            }
            WhPurchaseItem::insert($purchaseItems);
        }
    }
}
