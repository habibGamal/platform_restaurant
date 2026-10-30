<?php

namespace App\Domain\Ingestion\Validation;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

class DocumentRules
{
    public const DECIMAL_REGEX = '/^-?\d+(\.\d+)?$/';

    /**
     * @var list<string>
     */
    public const TYPES = [
        'order',
        'order_return',
        'shift',
        'expense',
        'purchase_invoice',
        'purchase_return',
        'waste',
        'stocktaking',
        'inventory_day',
        'day_summary',
        'product',
        'recipe',
        'category',
        'supplier',
        'expense_type',
        'staff',
    ];

    /**
     * Common rules for all POS documents.
     *
     * @return array<string, mixed>
     */
    public static function common(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'uuid' => ['required', 'string', 'size:36'],
            'version' => ['required', 'integer', 'min:1'],
            'op' => ['required', 'string', Rule::in(['upsert', 'delete'])],
            'occurred_at' => ['required', 'date'],
            'data' => ['exclude_if:op,delete', 'required', 'array'],
        ];
    }

    /**
     * Document validation rules for a given document type.
     *
     * @return array<string, mixed>
     */
    public static function for(string $type): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unsupported document type: {$type}");
        }

        return array_merge(self::common(), self::typeRules($type));
    }

    /**
     * Specific data rules for a document type (prefixed with data.).
     *
     * @return array<string, mixed>
     */
    public static function typeRules(string $type): array
    {
        return match ($type) {
            'order' => [
                'data.number' => ['required_if:op,upsert', 'string'],
                'data.shift_uuid' => ['required_if:op,upsert', 'string'],
                'data.business_date' => ['required_if:op,upsert', 'date_format:Y-m-d'],
                'data.type' => ['required_if:op,upsert', 'string'],
                'data.status' => ['required_if:op,upsert', 'string'],
                'data.payment_status' => ['required_if:op,upsert', 'string'],
                'data.return_status' => ['nullable', 'string'],
                'data.created_at' => ['required_if:op,upsert', 'date'],
                'data.cashier_uuid' => ['nullable', 'string'],
                'data.driver_uuid' => ['nullable', 'string'],
                'data.customer_hash' => ['nullable', 'string'],
                'data.region' => ['nullable', 'string'],
                'data.sub_total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.discount' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.tax' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.service' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.profit' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.web_pos_diff' => [
                    'exclude_unless:op,upsert',
                    'required_if:data.type,web_delivery,web_takeaway',
                    'nullable',
                    'regex:'.self::DECIMAL_REGEX,
                ],
                'data.items' => ['required_if:op,upsert', 'array'],
                'data.items.*.uuid' => ['required_if:op,upsert', 'string'],
                'data.items.*.product_uuid' => ['required_if:op,upsert', 'string'],
                'data.items.*.product_ref' => ['nullable', 'string'],
                'data.items.*.quantity' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.price' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.unit_cost' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.item_discount' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.item_discount_type' => ['nullable', 'string'],
                'data.items.*.total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.payments' => ['present_if:op,upsert', 'array'],
                'data.payments.*.method' => ['required_if:op,upsert', 'string'],
                'data.payments.*.amount' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.payments.*.shift_uuid' => ['nullable', 'string'],
                'data.payments.*.paid_at' => ['nullable', 'date'],
            ],
            'order_return' => [
                'data.order_uuid' => ['required_if:op,upsert', 'string'],
                'data.shift_uuid' => ['nullable', 'string'],
                'data.business_date' => ['required_if:op,upsert', 'date_format:Y-m-d'],
                'data.total_refund' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.reason' => ['nullable', 'string'],
                'data.reverse_stock' => ['nullable', 'boolean'],
                'data.items' => ['required_if:op,upsert', 'array'],
                'data.items.*.order_item_uuid' => ['required_if:op,upsert', 'string'],
                'data.items.*.quantity' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.refund_amount' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.refunds' => ['present_if:op,upsert', 'array'],
                'data.refunds.*.method' => ['required_if:op,upsert', 'string'],
                'data.refunds.*.amount' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
            ],
            'shift' => [
                'data.business_date' => ['required_if:op,upsert', 'date_format:Y-m-d'],
                'data.user_uuid' => ['required_if:op,upsert', 'string'],
                'data.start_at' => ['required_if:op,upsert', 'date'],
                'data.end_at' => ['nullable', 'date'],
                'data.start_cash' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.end_cash' => ['nullable', 'regex:'.self::DECIMAL_REGEX],
                'data.real_cash' => ['nullable', 'regex:'.self::DECIMAL_REGEX],
                'data.losses_amount' => ['nullable', 'regex:'.self::DECIMAL_REGEX],
                'data.has_deficit' => ['nullable', 'boolean'],
                'data.closed' => ['nullable', 'boolean'],
            ],
            'expense' => [
                'data.shift_uuid' => ['nullable', 'string'],
                'data.business_date' => ['required_if:op,upsert', 'date_format:Y-m-d'],
                'data.expense_type_uuid' => ['required_if:op,upsert', 'string'],
                'data.amount' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.notes' => ['nullable', 'string'],
            ],
            'purchase_invoice', 'purchase_return' => [
                'data.business_date' => ['required_if:op,upsert', 'date_format:Y-m-d'],
                'data.closed_at' => ['nullable', 'date'],
                'data.supplier_uuid' => ['required_if:op,upsert', 'string'],
                'data.total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items' => ['required_if:op,upsert', 'array'],
                'data.items.*.product_uuid' => ['required_if:op,upsert', 'string'],
                'data.items.*.quantity' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.price' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
            ],
            'waste' => [
                'data.business_date' => ['required_if:op,upsert', 'date_format:Y-m-d'],
                'data.closed_at' => ['nullable', 'date'],
                'data.total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items' => ['required_if:op,upsert', 'array'],
                'data.items.*.product_uuid' => ['required_if:op,upsert', 'string'],
                'data.items.*.quantity' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.price' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
            ],
            'stocktaking' => [
                'data.business_date' => ['required_if:op,upsert', 'date_format:Y-m-d'],
                'data.closed_at' => ['nullable', 'date'],
                'data.total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items' => ['required_if:op,upsert', 'array'],
                'data.items.*.product_uuid' => ['required_if:op,upsert', 'string'],
                'data.items.*.stock_quantity' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.real_quantity' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.price' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.items.*.total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
            ],
            'inventory_day' => [
                'data.date' => ['required_if:op,upsert', 'date_format:Y-m-d'],
                'data.rows' => ['present_if:op,upsert', 'array'],
                'data.rows.*.product_uuid' => ['required_if:op,upsert', 'string'],
                'data.rows.*.start_qty' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.rows.*.incoming_qty' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.rows.*.sales_qty' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.rows.*.return_sales_qty' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.rows.*.waste_qty' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.rows.*.end_qty' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
            ],
            'day_summary' => [
                'data.date' => ['required_if:op,upsert', 'date_format:Y-m-d'],
                'data.orders_count' => ['required_if:op,upsert', 'integer'],
                'data.completed_count' => ['required_if:op,upsert', 'integer'],
                'data.net_total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.discount_total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.cogs_total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.expenses_total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.waste_total' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
            ],
            'product' => [
                'data.name' => ['required_if:op,upsert', 'string'],
                'data.product_ref' => ['nullable', 'string'],
                'data.category_uuid' => ['nullable', 'string'],
                'data.type' => ['required_if:op,upsert', 'string'],
                'data.unit' => ['nullable', 'string'],
                'data.price' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.cost' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
                'data.legacy' => ['nullable', 'boolean'],
            ],
            'recipe' => [
                'data.product_uuid' => ['required_if:op,upsert', 'string'],
                'data.components' => ['present_if:op,upsert', 'array'],
                'data.components.*.component_uuid' => ['required_if:op,upsert', 'string'],
                'data.components.*.quantity' => ['required_if:op,upsert', 'regex:'.self::DECIMAL_REGEX],
            ],
            'category' => [
                'data.name' => ['required_if:op,upsert', 'string'],
            ],
            'supplier' => [
                'data.name' => ['required_if:op,upsert', 'string'],
                'data.phone' => ['nullable', 'string'],
            ],
            'expense_type' => [
                'data.name' => ['required_if:op,upsert', 'string'],
                'data.category' => ['required_if:op,upsert', 'string'],
                'data.avg_month_rate' => ['nullable', 'regex:'.self::DECIMAL_REGEX],
            ],
            'staff' => [
                'data.name' => ['required_if:op,upsert', 'string'],
                'data.role' => ['required_if:op,upsert', 'string'],
            ],
            default => throw new InvalidArgumentException("Unsupported document type: {$type}"),
        };
    }
}
