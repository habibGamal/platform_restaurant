<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_dirty_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingest_batch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status', 16)->default('pending'); // pending | dispatched | processed | failed
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('recomputed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['ingest_batch_id', 'branch_id', 'business_date'], 'dirty_dates_batch_branch_date_unique');
            $table->index(['status', 'dispatched_at'], 'dirty_dates_status_dispatched_idx');
            $table->index(['branch_id', 'business_date', 'status'], 'dirty_dates_branch_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_dirty_dates');
    }
};
