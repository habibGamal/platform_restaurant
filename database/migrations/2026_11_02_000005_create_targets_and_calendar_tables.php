<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $t->string('metric', 32);          // net_sales | net_profit | orders | food_cost_pct
            $t->date('period_month');          // first day of the month
            $t->decimal('value', 14, 4);       // amount, count, or ratio (0..1) for food_cost_pct
            $t->string('distribution', 16)->default('weekday_weights'); // weekday_weights | flat
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['branch_id', 'metric', 'period_month']);
        });

        Schema::create('target_daily', function (Blueprint $t) {
            $t->foreignId('target_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->decimal('value', 14, 4);
            $t->primary(['target_id', 'date']);
        });

        Schema::create('calendar_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete(); // null = global
            $t->string('name');
            $t->string('kind', 16); // ramadan | eid | holiday | promo
            $t->date('date_from');
            $t->date('date_to');
            $t->timestamps();
            $t->index(['date_from', 'date_to']);
        });

        Schema::create('scoring_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->json('weights'); // {"net_margin": 25, "sales_growth": 20, ...} sums to 100
            $t->timestamps();
            $t->unique('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scoring_profiles');
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('target_daily');
        Schema::dropIfExists('targets');
    }
};
