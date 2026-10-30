<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'wh_orders', 'wh_order_items', 'wh_payments', 'wh_order_returns',
        'wh_order_return_items', 'wh_refunds', 'wh_shifts', 'wh_expenses',
        'wh_purchases', 'wh_purchase_items', 'wh_wastes', 'wh_waste_items',
        'wh_stocktakings', 'wh_stocktaking_items', 'wh_inventory_days',
        'wh_inventory_day_rows', 'wh_day_summaries',
        'dim_products', 'dim_recipes', 'dim_categories', 'dim_suppliers',
        'dim_expense_types', 'dim_staff',
        'agg_branch_daily', 'agg_product_daily', 'agg_shift',
        'agg_branch_hourly', 'agg_expense_daily', 'agg_purchase_daily',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            // Remove leftovers of branches deleted before branch cascades existed.
            DB::table($table)->whereNotExists(function ($query) use ($table): void {
                $query->selectRaw('1')->from('branches')->whereColumn('branches.id', "{$table}.branch_id");
            })->delete();

            $name = "{$table}_branch_owner_foreign";
            if (in_array($name, array_column(Schema::getForeignKeys($table), 'name'), true)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->foreign('branch_id', $name)->references('id')->on('branches')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign("{$table}_branch_owner_foreign");
            });
        }
    }
};
