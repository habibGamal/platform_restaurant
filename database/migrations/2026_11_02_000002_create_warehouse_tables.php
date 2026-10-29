<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse = mirror of POS documents (architecture §6.2).
 * No foreign keys between warehouse tables: documents arrive in any order and join by (branch_id, uuid).
 * Parent tables: unique (branch_id, uuid) + source_version (POS outbox seq) for the version guard.
 * Child tables are replaced wholesale when their parent is updated.
 */
return new class extends Migration
{
    private function parent(Blueprint $t, bool $withBusinessDate = true): void
    {
        $t->id();
        $t->unsignedBigInteger('organization_id')->index();
        $t->unsignedBigInteger('branch_id');
        $t->char('uuid', 36);
        $t->unsignedBigInteger('source_version');
        if ($withBusinessDate) {
            $t->date('business_date');
        }
        $t->timestamp('ingested_at')->nullable();
        $t->softDeletes();
        $t->unique(['branch_id', 'uuid']);
        if ($withBusinessDate) {
            $t->index(['branch_id', 'business_date']);
        }
    }

    private function child(Blueprint $t, string $parentUuidColumn): void
    {
        $t->id();
        $t->unsignedBigInteger('organization_id')->index();
        $t->unsignedBigInteger('branch_id');
        $t->char($parentUuidColumn, 36);
        $t->index(['branch_id', $parentUuidColumn]);
    }

    public function up(): void
    {
        Schema::create('wh_orders', function (Blueprint $t) {
            $this->parent($t);
            $t->string('number', 32)->nullable();
            $t->char('shift_uuid', 36)->nullable();
            $t->string('type', 32);               // dine_in, takeaway, delivery, companies, talabat, web_delivery, web_takeaway
            $t->string('status', 32);             // pending, processing, out_for_delivery, completed, cancelled
            $t->string('payment_status', 32)->nullable();
            $t->string('return_status', 32)->nullable();
            $t->timestamp('created_at_local')->nullable();
            $t->unsignedTinyInteger('hour_of_day')->nullable(); // business-day hour (0-23, from created_at_local)
            $t->unsignedTinyInteger('weekday')->nullable();     // 0=Saturday .. 6=Friday
            $t->char('cashier_uuid', 36)->nullable();
            $t->char('driver_uuid', 36)->nullable();
            $t->string('customer_hash', 80)->nullable();
            $t->string('region')->nullable();
            $t->decimal('sub_total', 14, 2)->default(0);
            $t->decimal('discount', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('service', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->decimal('profit_pos', 14, 2)->nullable();
            $t->decimal('web_pos_diff', 14, 2)->nullable();
            $t->decimal('items_discount', 14, 2)->default(0); // Σ items.item_discount (computed at ingest)
            $t->decimal('cogs', 14, 2)->default(0);           // Σ items.quantity × unit_cost (computed at ingest)
            $t->index(['branch_id', 'business_date', 'status']);
        });

        Schema::create('wh_order_items', function (Blueprint $t) {
            $this->child($t, 'order_uuid');
            $t->char('uuid', 36);
            $t->char('product_uuid', 36)->nullable();
            $t->string('product_ref', 64)->nullable();
            $t->decimal('quantity', 14, 3);
            $t->decimal('price', 14, 2);
            $t->decimal('unit_cost', 14, 2)->default(0);
            $t->decimal('item_discount', 14, 2)->default(0);
            $t->string('item_discount_type', 16)->nullable();
            $t->decimal('total', 14, 2);
            $t->index(['branch_id', 'uuid']);
            $t->index(['branch_id', 'product_uuid']);
        });

        Schema::create('wh_payments', function (Blueprint $t) {
            $this->child($t, 'order_uuid');
            $t->string('method', 32);
            $t->decimal('amount', 14, 2);
            $t->char('shift_uuid', 36)->nullable();
            $t->timestamp('paid_at')->nullable();
        });

        Schema::create('wh_order_returns', function (Blueprint $t) {
            $this->parent($t);
            $t->char('order_uuid', 36);
            $t->char('shift_uuid', 36)->nullable();
            $t->decimal('total_refund', 14, 2)->default(0);
            $t->text('reason')->nullable();
            $t->boolean('reverse_stock')->default(true);
            $t->decimal('returned_cogs', 14, 2)->default(0); // Σ returned qty × original unit_cost (computed at ingest)
        });

        Schema::create('wh_order_return_items', function (Blueprint $t) {
            $this->child($t, 'return_uuid');
            $t->char('order_item_uuid', 36);
            $t->decimal('quantity', 14, 3);
            $t->decimal('refund_amount', 14, 2);
        });

        Schema::create('wh_refunds', function (Blueprint $t) {
            $this->child($t, 'return_uuid');
            $t->string('method', 32);
            $t->decimal('amount', 14, 2);
        });

        Schema::create('wh_shifts', function (Blueprint $t) {
            $this->parent($t);
            $t->char('user_uuid', 36)->nullable();
            $t->timestamp('start_at')->nullable();
            $t->timestamp('end_at')->nullable();
            $t->decimal('start_cash', 14, 2)->default(0);
            $t->decimal('end_cash', 14, 2)->nullable();
            $t->decimal('real_cash', 14, 2)->nullable();
            $t->decimal('losses_amount', 14, 2)->nullable();
            $t->boolean('has_deficit')->default(false);
            $t->boolean('closed')->default(false);
        });

        Schema::create('wh_expenses', function (Blueprint $t) {
            $this->parent($t);
            $t->char('shift_uuid', 36)->nullable();
            $t->char('expense_type_uuid', 36)->nullable();
            $t->decimal('amount', 14, 2);
            $t->text('notes')->nullable();
        });

        Schema::create('wh_purchases', function (Blueprint $t) {
            $this->parent($t);
            $t->string('kind', 16); // invoice | return
            $t->timestamp('closed_at')->nullable();
            $t->char('supplier_uuid', 36)->nullable();
            $t->decimal('total', 14, 2);
        });

        Schema::create('wh_purchase_items', function (Blueprint $t) {
            $this->child($t, 'purchase_uuid');
            $t->char('product_uuid', 36)->nullable();
            $t->decimal('quantity', 14, 3);
            $t->decimal('price', 14, 2);
            $t->decimal('total', 14, 2);
        });

        Schema::create('wh_wastes', function (Blueprint $t) {
            $this->parent($t);
            $t->timestamp('closed_at')->nullable();
            $t->decimal('total', 14, 2);
        });

        Schema::create('wh_waste_items', function (Blueprint $t) {
            $this->child($t, 'waste_uuid');
            $t->char('product_uuid', 36)->nullable();
            $t->decimal('quantity', 14, 3);
            $t->decimal('price', 14, 2);
            $t->decimal('total', 14, 2);
        });

        Schema::create('wh_stocktakings', function (Blueprint $t) {
            $this->parent($t);
            $t->timestamp('closed_at')->nullable();
            $t->decimal('total', 14, 2);
            $t->decimal('variance_cost', 14, 2)->default(0); // Σ (stock_qty − real_qty) × price (computed at ingest)
        });

        Schema::create('wh_stocktaking_items', function (Blueprint $t) {
            $this->child($t, 'stocktaking_uuid');
            $t->char('product_uuid', 36)->nullable();
            $t->decimal('stock_quantity', 14, 3);
            $t->decimal('real_quantity', 14, 3);
            $t->decimal('price', 14, 2);
            $t->decimal('total', 14, 2);
        });

        // inventory_day document: parent row per (branch, date) + rows
        Schema::create('wh_inventory_days', function (Blueprint $t) {
            $this->parent($t);
        });

        Schema::create('wh_inventory_day_rows', function (Blueprint $t) {
            $this->child($t, 'inventory_day_uuid');
            $t->date('business_date');
            $t->char('product_uuid', 36);
            $t->decimal('start_qty', 14, 3)->default(0);
            $t->decimal('incoming_qty', 14, 3)->default(0);
            $t->decimal('sales_qty', 14, 3)->default(0);
            $t->decimal('return_sales_qty', 14, 3)->default(0);
            $t->decimal('waste_qty', 14, 3)->default(0);
            $t->decimal('end_qty', 14, 3)->default(0);
            $t->index(['branch_id', 'business_date', 'product_uuid'], 'wh_inv_rows_branch_date_product_idx');
        });

        Schema::create('wh_day_summaries', function (Blueprint $t) {
            $this->parent($t);
            $t->unsignedInteger('orders_count')->default(0);
            $t->unsignedInteger('completed_count')->default(0);
            $t->decimal('net_total', 14, 2)->default(0);
            $t->decimal('discount_total', 14, 2)->default(0);
            $t->decimal('cogs_total', 14, 2)->default(0);
            $t->decimal('expenses_total', 14, 2)->default(0);
            $t->decimal('waste_total', 14, 2)->default(0);
        });
    }

    public function down(): void
    {
        foreach ([
            'wh_day_summaries', 'wh_inventory_day_rows', 'wh_inventory_days', 'wh_stocktaking_items', 'wh_stocktakings',
            'wh_waste_items', 'wh_wastes', 'wh_purchase_items', 'wh_purchases', 'wh_expenses', 'wh_shifts',
            'wh_refunds', 'wh_order_return_items', 'wh_order_returns', 'wh_payments', 'wh_order_items', 'wh_orders',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
