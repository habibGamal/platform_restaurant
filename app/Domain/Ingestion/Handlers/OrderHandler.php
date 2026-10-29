<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\WhOrder;
use App\Models\Warehouse\WhOrderItem;
use App\Models\Warehouse\WhPayment;
use App\Support\Weekday;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class OrderHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'order';
    }

    protected function modelClass(): string
    {
        return WhOrder::class;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        $items = (array) ($data['items'] ?? []);

        // Compute cogs = Σ items quantity × unit_cost
        $totalCogs = '0.0000';
        foreach ($items as $item) {
            $lineCost = $this->bcMultiply($item['quantity'] ?? '0', $item['unit_cost'] ?? '0', 4);
            $totalCogs = $this->bcAdd($totalCogs, $lineCost, 4);
        }
        $cogs = number_format(round((float) $totalCogs, 2), 2, '.', '');

        // Compute items_discount = Σ items item_discount
        $totalDiscount = '0.00';
        foreach ($items as $item) {
            $totalDiscount = $this->bcAdd($totalDiscount, $item['item_discount'] ?? '0', 2);
        }
        $itemsDiscount = number_format(round((float) $totalDiscount, 2), 2, '.', '');

        // created_at converted to branch timezone
        $tz = $branch->timezone ?: 'Africa/Cairo';
        $createdAtLocal = isset($data['created_at'])
            ? Carbon::parse($data['created_at'])->setTimezone($tz)
            : null;
        $hourOfDay = $createdAtLocal?->hour;

        // weekday = App\Support\Weekday::of(business_date)
        $businessDate = $data['business_date'] ?? null;
        $weekday = $businessDate ? Weekday::of(Carbon::parse($businessDate)) : null;

        $profitPos = isset($data['profit_pos'])
            ? (string) $data['profit_pos']
            : (isset($data['profit']) ? (string) $data['profit'] : null);

        $webPosDiff = isset($data['web_pos_diff']) ? (string) $data['web_pos_diff'] : null;

        return [
            'number' => $data['number'] ?? null,
            'shift_uuid' => $data['shift_uuid'] ?? null,
            'business_date' => $businessDate,
            'type' => (string) ($data['type'] ?? 'dine_in'),
            'status' => (string) ($data['status'] ?? 'completed'),
            'payment_status' => $data['payment_status'] ?? null,
            'return_status' => $data['return_status'] ?? null,
            'created_at_local' => $createdAtLocal?->format('Y-m-d H:i:s'),
            'hour_of_day' => $hourOfDay,
            'weekday' => $weekday,
            'cashier_uuid' => $data['cashier_uuid'] ?? null,
            'driver_uuid' => $data['driver_uuid'] ?? null,
            'customer_hash' => $data['customer_hash'] ?? null,
            'region' => $data['region'] ?? null,
            'sub_total' => (string) ($data['sub_total'] ?? '0.00'),
            'discount' => (string) ($data['discount'] ?? '0.00'),
            'tax' => (string) ($data['tax'] ?? '0.00'),
            'service' => (string) ($data['service'] ?? '0.00'),
            'total' => (string) ($data['total'] ?? '0.00'),
            'profit_pos' => $profitPos,
            'web_pos_diff' => $webPosDiff,
            'items_discount' => $itemsDiscount,
            'cogs' => $cogs,
        ];
    }

    protected function replaceChildren(Branch $branch, Model $parent, array $data, array $document): void
    {
        WhOrderItem::where('branch_id', $branch->id)
            ->where('order_uuid', $parent->getAttribute('uuid'))
            ->delete();

        WhPayment::where('branch_id', $branch->id)
            ->where('order_uuid', $parent->getAttribute('uuid'))
            ->delete();

        $items = (array) ($data['items'] ?? []);
        if (! empty($items)) {
            $orderItems = [];
            foreach ($items as $item) {
                $orderItems[] = [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'order_uuid' => $parent->getAttribute('uuid'),
                    'uuid' => (string) $item['uuid'],
                    'product_uuid' => $item['product_uuid'] ?? null,
                    'product_ref' => $item['product_ref'] ?? null,
                    'quantity' => (string) ($item['quantity'] ?? '0.000'),
                    'price' => (string) ($item['price'] ?? '0.00'),
                    'unit_cost' => (string) ($item['unit_cost'] ?? '0.00'),
                    'item_discount' => (string) ($item['item_discount'] ?? '0.00'),
                    'item_discount_type' => $item['item_discount_type'] ?? null,
                    'total' => (string) ($item['total'] ?? '0.00'),
                ];
            }
            WhOrderItem::insert($orderItems);
        }

        $payments = (array) ($data['payments'] ?? []);
        if (! empty($payments)) {
            $orderPayments = [];
            foreach ($payments as $payment) {
                $paidAt = isset($payment['paid_at'])
                    ? Carbon::parse($payment['paid_at'])->toDateTimeString()
                    : null;

                $orderPayments[] = [
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'order_uuid' => $parent->getAttribute('uuid'),
                    'method' => (string) $payment['method'],
                    'amount' => (string) ($payment['amount'] ?? '0.00'),
                    'shift_uuid' => $payment['shift_uuid'] ?? null,
                    'paid_at' => $paidAt,
                ];
            }
            WhPayment::insert($orderPayments);
        }
    }
}
