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
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('provider');
            $table->string('model');
            $table->json('failover')->nullable();
            $table->string('reasoning_level')->default('medium');
            $table->text('byo_key')->nullable();
            $table->decimal('monthly_budget_egp', 12, 2)->nullable();
            $table->decimal('per_user_monthly_egp', 12, 2)->nullable();
            $table->decimal('warn_at', 4, 2)->default(0.80);
            $table->boolean('hard_stop')->default(true);
            $table->timestamp('data_sharing_ack_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
