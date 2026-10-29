<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregates (architecture §6.3) — the ONLY tables report pages read (through MetricsQuery).
 * Rebuilt per (branch_id, business_date) by the BranchDayAggregator. Column meanings: .plans/reports/00-conventions.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agg_branch_daily', function (Blueprint $t) {
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('restaurant_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('business_date');
            $t->unsignedTinyInteger('weekday'); // 0=Saturday .. 6=Friday
            $t->unsignedInteger('orders_count')->default(0);       // K01 completed
            $t->unsignedInteger('cancelled_count')->default(0);
            $t->decimal('cancelled_value', 14, 2)->default(0);
            $t->decimal('gross_sales', 14, 2)->default(0);           // K02 Σ sub_total
            $t->decimal('discounts', 14, 2)->default(0);             // K03 Σ discount + Σ item_discount
            $t->decimal('tax', 14, 2)->default(0);
            $t->decimal('service', 14, 2)->default(0);
            $t->decimal('net_sales', 14, 2)->default(0);             // K04 Σ (total − tax)
            $t->decimal('returns_amount', 14, 2)->default(0);        // K05 by return business_date
            $t->decimal('net_sales_after_returns', 14, 2)->default(0); // K06
            $t->decimal('cogs', 14, 2)->default(0);                  // Σ qty × unit_cost (completed)
            $t->decimal('returned_cogs_recovered', 14, 2)->default(0); // returns with reverse_stock = true
            $t->decimal('gross_profit', 14, 2)->default(0);          // K09 = K06 − (cogs − recovered)
            $t->decimal('expenses_total', 14, 2)->default(0);        // K11
            $t->decimal('labor_cost', 14, 2)->default(0);            // K11a (paid)
            $t->decimal('rent_cost', 14, 2)->default(0);
            $t->decimal('other_expenses', 14, 2)->default(0);
            $t->decimal('waste_cost', 14, 2)->default(0);            // K12
            $t->decimal('inventory_variance_cost', 14, 2)->default(0); // K13
            $t->decimal('purchases_total', 14, 2)->default(0);
            $t->decimal('purchase_returns_total', 14, 2)->default(0);
            $t->decimal('net_profit', 14, 2)->default(0);            // K14
            $t->json('sales_by_type')->nullable();   // {"delivery": {"orders": 12, "net_sales": "4300.00"}, ...}
            $t->json('sales_by_method')->nullable(); // {"cash": "12000.00", "card": "800.00", "talabat_card": "0.00"}
            $t->unsignedSmallInteger('shifts_count')->default(0);
            $t->decimal('cash_deficit_total', 14, 2)->default(0);    // K25
            $t->boolean('is_day_closed')->default(false);            // inventory_day / day_summary received
            $t->boolean('is_reconciled')->default(false);            // day_summary matches
            $t->timestamp('computed_at')->nullable();
            $t->primary(['branch_id', 'business_date']);
            $t->index(['organization_id', 'business_date']);
        });

        Schema::create('agg_product_daily', function (Blueprint $t) {
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('business_date');
            $t->char('product_uuid', 36);
            $t->char('category_uuid', 36)->nullable();
            $t->string('product_ref', 64)->nullable();
            $t->decimal('qty_sold', 14, 3)->default(0);
            $t->decimal('gross_sales', 14, 2)->default(0);   // Σ quantity × price
            $t->decimal('item_discounts', 14, 2)->default(0);
            $t->decimal('net_sales', 14, 2)->default(0);     // Σ item total
            $t->decimal('cogs', 14, 2)->default(0);
            $t->decimal('gross_profit', 14, 2)->default(0);
            $t->decimal('returns_qty', 14, 3)->default(0);
            $t->decimal('waste_qty', 14, 3)->default(0);
            $t->decimal('waste_cost', 14, 2)->default(0);
            $t->primary(['branch_id', 'business_date', 'product_uuid']);
            $t->index(['organization_id', 'business_date']);
        });

        Schema::create('agg_shift', function (Blueprint $t) {
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('branch_id');
            $t->char('shift_uuid', 36);
            $t->date('business_date');
            $t->unsignedTinyInteger('weekday');
            $t->timestamp('start_at')->nullable();
            $t->timestamp('end_at')->nullable();
            $t->unsignedInteger('duration_minutes')->nullable();
            $t->char('cashier_uuid', 36)->nullable();
            $t->unsignedInteger('orders_count')->default(0);
            $t->decimal('net_sales', 14, 2)->default(0);
            $t->decimal('gross_profit', 14, 2)->default(0);
            $t->decimal('avg_ticket', 14, 2)->nullable();
            $t->unsignedInteger('cancelled_count')->default(0);
            $t->decimal('cash_deficit', 14, 2)->default(0);
            $t->primary(['branch_id', 'shift_uuid']);
            $t->index(['organization_id', 'business_date']);
        });

        Schema::create('agg_branch_hourly', function (Blueprint $t) {
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('business_date');
            $t->unsignedTinyInteger('hour');
            $t->unsignedTinyInteger('weekday');
            $t->unsignedInteger('orders_count')->default(0);
            $t->decimal('net_sales', 14, 2)->default(0);
            $t->primary(['branch_id', 'business_date', 'hour']);
            $t->index(['organization_id', 'business_date']);
        });

        Schema::create('agg_expense_daily', function (Blueprint $t) {
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('business_date');
            $t->char('expense_type_uuid', 36);
            $t->string('category', 32);
            $t->decimal('amount', 14, 2)->default(0);
            $t->primary(['branch_id', 'business_date', 'expense_type_uuid']);
            $t->index(['organization_id', 'business_date']);
        });

        Schema::create('agg_purchase_daily', function (Blueprint $t) {
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('business_date');
            $t->char('supplier_uuid', 36)->default('');  // '' = no supplier
            $t->string('kind', 16);                      // invoice | return
            $t->decimal('amount', 14, 2)->default(0);
            $t->unsignedInteger('invoices_count')->default(0);
            $t->primary(['branch_id', 'business_date', 'supplier_uuid', 'kind']);
            $t->index(['organization_id', 'business_date']);
        });
    }

    public function down(): void
    {
        foreach (['agg_purchase_daily', 'agg_expense_daily', 'agg_branch_hourly', 'agg_shift', 'agg_product_daily', 'agg_branch_daily'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
