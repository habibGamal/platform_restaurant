<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\WhStocktaking;
use App\Models\Warehouse\WhStocktakingItem;
use Illuminate\Database\Eloquent\Model;

class StocktakingHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'stocktaking';
    }

    protected function modelClass(): string
    {
        return WhStocktaking::class;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        $items = (array) ($data['items'] ?? []);

        // wh_stocktakings.variance_cost = Σ (stock_quantity − real_quantity) × price
        $totalVariance = '0.0000';
        foreach ($items as $item) {
            $diff = $this->bcSubtract($item['stock_quantity'] ?? '0', $item['real_quantity'] ?? '0', 4);
            $line = $this->bcMultiply($diff, $item['price'] ?? '0', 4);
            $totalVariance = $this->bcAdd($totalVariance, $line, 4);
        }
        $varianceCost = number_format(round((float) $totalVariance, 2), 2, '.', '');

        return [
            'business_date' => $data['business_date'] ?? null,
            'closed_at' => $this->parseTimestamp($data['closed_at'] ?? null),
            'total' => (string) ($data['total'] ?? '0.00'),
            'variance_cost' => $varianceCost,
        ];
    }

    protected function replaceChildren(Branch $branch, Model $parent, array $data, array $document): void
    {
        WhStocktakingItem::where('branch_id', $branch->id)
            ->where('stocktaking_uuid', $parent->getAttribute('uuid'))
            ->delete();

        $items = (array) ($data['items'] ?? []);
        if (! empty($items)) {
            $stocktakingItems = [];
            foreach ($items as $item) {
                $stocktakingItems[] = [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'stocktaking_uuid' => $parent->getAttribute('uuid'),
                    'product_uuid' => $item['product_uuid'] ?? null,
                    'stock_quantity' => (string) ($item['stock_quantity'] ?? '0.000'),
                    'real_quantity' => (string) ($item['real_quantity'] ?? '0.000'),
                    'price' => (string) ($item['price'] ?? '0.00'),
                    'total' => (string) ($item['total'] ?? '0.00'),
                ];
            }
            WhStocktakingItem::insert($stocktakingItems);
        }
    }
}
