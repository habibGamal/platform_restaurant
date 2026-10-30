<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingest_dirty_dates', function (Blueprint $table) {
            $table->unsignedBigInteger('generation')->default(1)->after('business_date');
            $table->index(['branch_id', 'business_date', 'status', 'generation'], 'dirty_dates_branch_date_status_gen_idx');
        });

        Schema::create('ingest_recompute_fences', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedBigInteger('fence_token')->default(0);
            $table->unsignedBigInteger('committed_token')->default(0);
            $table->string('lock_token', 64)->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();

            $table->primary(['branch_id', 'business_date'], 'recompute_fences_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_recompute_fences');

        Schema::table('ingest_dirty_dates', function (Blueprint $table) {
            $table->dropIndex('dirty_dates_branch_date_status_gen_idx');
            $table->dropColumn('generation');
        });
    }
};
