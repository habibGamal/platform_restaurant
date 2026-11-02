<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_run_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 36)->nullable();
            $table->string('message_id', 36)->nullable()->index();
            $table->unsignedInteger('seq');
            $table->string('type', 24);
            $table->json('payload');
            $table->timestamp('created_at')->index();

            // Also serves lookups by run_id alone.
            $table->unique(['run_id', 'seq']);
            // Resuming the latest run of a conversation.
            $table->index(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_run_events');
    }
};
