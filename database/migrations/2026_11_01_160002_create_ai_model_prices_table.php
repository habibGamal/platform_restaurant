<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_model_prices', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('model');
            $table->decimal('input_per_1m', 12, 6);
            $table->decimal('output_per_1m', 12, 6);
            $table->decimal('cache_read_per_1m', 12, 6)->default(0);
            $table->decimal('cache_write_per_1m', 12, 6)->default(0);
            $table->string('currency')->default('USD');
            $table->date('effective_from');
            $table->timestamps();

            $table->unique(['provider', 'model', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_model_prices');
    }
};
