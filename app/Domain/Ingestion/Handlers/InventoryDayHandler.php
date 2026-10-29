<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\WhInventoryDay;
use App\Models\Warehouse\WhInventoryDayRow;
use Illuminate\Database\Eloquent\Model;

class InventoryDayHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'inventory_day';
    }

    protected function modelClass(): string
    {
        return WhInventoryDay::class;
    }

    protected function isDayClosed(): bool
    {
        return true;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        $businessDate = $data['date'] ?? $data['business_date'] ?? null;

        return [
            'business_date' => $businessDate,
        ];
    }

    protected function replaceChildren(Branch $branch, Model $parent, array $data, array $document): void
    {
        WhInventoryDayRow::where('branch_id', $branch->id)
            ->where('inventory_day_uuid', $parent->getAttribute('uuid'))
            ->delete();

        $rows = (array) ($data['rows'] ?? []);
        $businessDate = $parent->getAttribute('business_date');
        $businessDateStr = $businessDate instanceof \DateTimeInterface
            ? $businessDate->format('Y-m-d')
            : (string) $businessDate;

        if (! empty($rows)) {
            $inventoryRows = [];
            foreach ($rows as $row) {
                $inventoryRows[] = [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'inventory_day_uuid' => $parent->getAttribute('uuid'),
                    'business_date' => $businessDateStr,
                    'product_uuid' => (string) $row['product_uuid'],
                    'start_qty' => (string) ($row['start_qty'] ?? '0.000'),
                    'incoming_qty' => (string) ($row['incoming_qty'] ?? '0.000'),
                    'sales_qty' => (string) ($row['sales_qty'] ?? '0.000'),
                    'return_sales_qty' => (string) ($row['return_sales_qty'] ?? '0.000'),
                    'waste_qty' => (string) ($row['waste_qty'] ?? '0.000'),
                    'end_qty' => (string) ($row['end_qty'] ?? '0.000'),
                ];
            }
            WhInventoryDayRow::insert($inventoryRows);
        }
    }
}
