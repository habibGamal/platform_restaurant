<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('item_key', 64);
            $table->string('role', 16);
            $table->string('source', 8); // owner | signal | auto
            $table->decimal('confidence', 3, 2);
            $table->timestamp('classified_at');
            $table->timestamps();

            $table->unique(['organization_id', 'item_key']);
        });

        Schema::create('category_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('category_key', 191);
            $table->string('role', 16)->nullable(); // null = mixed category
            $table->decimal('confidence', 3, 2);
            $table->string('source', 8); // owner | signal | auto
            $table->timestamps();

            $table->unique(['organization_id', 'category_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_roles');
        Schema::dropIfExists('item_roles');
    }
};
