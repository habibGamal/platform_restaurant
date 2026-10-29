<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reference data mirrored from each POS (architecture §6.2, "dimensions"). */
return new class extends Migration
{
    private function dim(Blueprint $t): void
    {
        $t->id();
        $t->unsignedBigInteger('organization_id')->index();
        $t->unsignedBigInteger('branch_id');
        $t->char('uuid', 36);
        $t->unsignedBigInteger('source_version');
        $t->timestamp('ingested_at')->nullable();
        $t->softDeletes();
        $t->unique(['branch_id', 'uuid']);
    }

    public function up(): void
    {
        Schema::create('dim_products', function (Blueprint $t) {
            $this->dim($t);
            $t->string('name');
            $t->string('product_ref', 64)->nullable();
            $t->char('category_uuid', 36)->nullable();
            $t->string('type', 32)->nullable(); // manufactured | raw_material | consumable
            $t->string('unit', 32)->nullable();
            $t->decimal('price', 14, 2)->default(0);
            $t->decimal('cost', 14, 2)->default(0);
            $t->boolean('legacy')->default(false);
            $t->index(['organization_id', 'product_ref']);
        });

        // recipe document: parent per product + component rows
        Schema::create('dim_recipes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('organization_id')->index();
            $t->unsignedBigInteger('branch_id');
            $t->char('product_uuid', 36);
            $t->char('component_uuid', 36);
            $t->decimal('quantity', 14, 4);
            $t->unsignedBigInteger('source_version');
            $t->index(['branch_id', 'product_uuid']);
        });

        Schema::create('dim_categories', function (Blueprint $t) {
            $this->dim($t);
            $t->string('name');
        });

        Schema::create('dim_suppliers', function (Blueprint $t) {
            $this->dim($t);
            $t->string('name');
            $t->string('phone', 32)->nullable();
        });

        Schema::create('dim_expense_types', function (Blueprint $t) {
            $this->dim($t);
            $t->string('name');
            $t->string('category', 32)->default('other');      // as sent by POS
            $t->string('category_override', 32)->nullable();     // set on the platform; wins over category
            $t->decimal('avg_month_rate', 14, 2)->nullable();     // monthly budget
        });

        Schema::create('dim_staff', function (Blueprint $t) {
            $this->dim($t);
            $t->string('name');
            $t->string('role', 64)->nullable();
        });

        Schema::create('catalog_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('product_ref', 64);
            $t->string('canonical_name');
            $t->decimal('unit_factor', 14, 6)->default(1);
            $t->timestamps();
            $t->unique(['organization_id', 'product_ref']);
        });
    }

    public function down(): void
    {
        foreach (['catalog_items', 'dim_staff', 'dim_expense_types', 'dim_suppliers', 'dim_categories', 'dim_recipes', 'dim_products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
