<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\WhWaste;
use App\Models\Warehouse\WhWasteItem;
use Illuminate\Database\Eloquent\Model;

class WasteHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'waste';
    }

    protected function modelClass(): string
    {
        return WhWaste::class;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        return [
            'business_date' => $data['business_date'] ?? null,
            'closed_at' => $this->parseTimestamp($data['closed_at'] ?? null),
            'total' => (string) ($data['total'] ?? '0.00'),
        ];
    }

    protected function replaceChildren(Branch $branch, Model $parent, array $data, array $document): void
    {
        WhWasteItem::where('branch_id', $branch->id)
            ->where('waste_uuid', $parent->getAttribute('uuid'))
            ->delete();

        $items = (array) ($data['items'] ?? []);
        if (! empty($items)) {
            $wasteItems = [];
            foreach ($items as $item) {
                $wasteItems[] = [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'waste_uuid' => $parent->getAttribute('uuid'),
                    'product_uuid' => $item['product_uuid'] ?? null,
                    'quantity' => (string) ($item['quantity'] ?? '0.000'),
                    'price' => (string) ($item['price'] ?? '0.00'),
                    'total' => (string) ($item['total'] ?? '0.00'),
                ];
            }
            WhWasteItem::insert($wasteItems);
        }
    }
}
