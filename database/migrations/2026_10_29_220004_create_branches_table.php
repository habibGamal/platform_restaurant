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
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone')->default('Africa/Cairo');
            $table->date('opened_on')->nullable();
            $table->string('status')->default('pending');
            $table->string('pos_version')->nullable();
            $table->unsignedSmallInteger('schema_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_batch_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedBigInteger('last_seq')->nullable();
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('oldest_pending_at')->nullable();
            $table->text('branch_secret')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
