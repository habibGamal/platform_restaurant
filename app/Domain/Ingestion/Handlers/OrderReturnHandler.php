<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\WhOrderItem;
use App\Models\Warehouse\WhOrderReturn;
use App\Models\Warehouse\WhOrderReturnItem;
use App\Models\Warehouse\WhRefund;
use Illuminate\Database\Eloquent\Model;

class OrderReturnHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'order_return';
    }

    protected function modelClass(): string
    {
        return WhOrderReturn::class;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        $items = (array) ($data['items'] ?? []);

        // wh_order_returns.returned_cogs = Σ return item quantity × unit_cost of the referenced wh_order_items row (by branch_id + order_item_uuid; 0 if unknown)
        $returnedCogs = '0.00';
        if (! empty($items)) {
            $orderItemUuids = array_values(array_filter(array_column($items, 'order_item_uuid')));
            $unitCosts = [];
            if (! empty($orderItemUuids)) {
                $unitCosts = WhOrderItem::where('branch_id', $branch->id)
                    ->whereIn('uuid', $orderItemUuids)
                    ->pluck('unit_cost', 'uuid')
                    ->all();
            }

            $totalReturnedCogs = '0.0000';
            foreach ($items as $item) {
                $orderItemUuid = (string) ($item['order_item_uuid'] ?? '');
                $unitCost = $unitCosts[$orderItemUuid] ?? '0';
                $line = $this->bcMultiply($item['quantity'] ?? '0', $unitCost, 4);
                $totalReturnedCogs = $this->bcAdd($totalReturnedCogs, $line, 4);
            }
            $returnedCogs = number_format(round((float) $totalReturnedCogs, 2), 2, '.', '');
        }

        return [
            'order_uuid' => (string) $data['order_uuid'],
            'shift_uuid' => $data['shift_uuid'] ?? null,
            'business_date' => $data['business_date'] ?? null,
            'total_refund' => (string) ($data['total_refund'] ?? '0.00'),
            'reason' => $data['reason'] ?? null,
            'reverse_stock' => (bool) ($data['reverse_stock'] ?? true),
            'returned_cogs' => $returnedCogs,
        ];
    }

    protected function replaceChildren(Branch $branch, Model $parent, array $data, array $document): void
    {
        WhOrderReturnItem::where('branch_id', $branch->id)
            ->where('return_uuid', $parent->getAttribute('uuid'))
            ->delete();

        WhRefund::where('branch_id', $branch->id)
            ->where('return_uuid', $parent->getAttribute('uuid'))
            ->delete();

        $items = (array) ($data['items'] ?? []);
        if (! empty($items)) {
            $returnItems = [];
            foreach ($items as $item) {
                $returnItems[] = [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'return_uuid' => $parent->getAttribute('uuid'),
                    'order_item_uuid' => (string) $item['order_item_uuid'],
                    'quantity' => (string) ($item['quantity'] ?? '0.000'),
                    'refund_amount' => (string) ($item['refund_amount'] ?? '0.00'),
                ];
            }
            WhOrderReturnItem::insert($returnItems);
        }

        $refunds = (array) ($data['refunds'] ?? []);
        if (! empty($refunds)) {
            $returnRefunds = [];
            foreach ($refunds as $refund) {
                $returnRefunds[] = [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'return_uuid' => $parent->getAttribute('uuid'),
                    'method' => (string) $refund['method'],
                    'amount' => (string) ($refund['amount'] ?? '0.00'),
                ];
            }
            WhRefund::insert($returnRefunds);
        }
    }
}
