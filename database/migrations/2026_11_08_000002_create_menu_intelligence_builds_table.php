<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (organization, period) whose menu intelligence build finished, even with no sales:
 * readiness is "the build completed", not "some stats rows exist".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_intelligence_builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 16);
            $table->string('build_id', 26);
            $table->json('rows'); // step => rows written
            $table->timestamp('completed_at')->useCurrent(); // explicit default, so MariaDB adds no ON UPDATE

            $table->unique(['organization_id', 'period_key'], 'mi_build_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_intelligence_builds');
    }
};
