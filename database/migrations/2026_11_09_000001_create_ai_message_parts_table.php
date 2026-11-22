<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_message_parts', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 36)->index();
            $table->string('conversation_id', 36);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 16);
            $table->string('tool_name', 64)->nullable();
            $table->string('tool_call_id', 64)->nullable();
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['message_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_message_parts');
    }
};
