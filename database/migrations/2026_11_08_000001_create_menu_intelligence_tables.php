<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Intelligence: the nightly-prepared facts the assistant reads instead of recomputing.
 * Scope sentinels keep the unique keys usable: branch_id 0 = all branches, channel 'all' = all channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('component_key', 64);
            $table->string('name');
            $table->string('class', 16);
            $table->string('source', 8); // owner | auto | rule
            $table->decimal('confidence', 3, 2);
            $table->timestamp('classified_at')->useCurrent(); // explicit default, so MariaDB adds no ON UPDATE
            $table->timestamps();

            $table->unique(['organization_id', 'component_key'], 'mi_component_unique');
        });

        Schema::create('category_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('category_key', 191);
            $table->string('name');
            $table->string('season', 16)->default('all');
            $table->string('meal_type', 16)->default('single');
            $table->string('source', 8); // owner | auto | rule
            $table->decimal('confidence', 3, 2);
            $table->timestamp('classified_at')->useCurrent(); // explicit default, so MariaDB adds no ON UPDATE
            $table->timestamps();

            $table->unique(['organization_id', 'category_key'], 'mi_category_unique');
        });

        Schema::create('item_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('item_key', 64);
            $table->string('name');
            $table->string('category_key', 191)->nullable();
            $table->string('role', 16)->nullable();
            $table->unsignedTinyInteger('serves_n')->default(1);
            $table->string('serves_source', 8); // recipe | category | owner | default
            $table->string('price_tier', 8);
            $table->string('price_tier_source', 8); // auto | owner
            $table->string('season', 16)->default('all');
            $table->string('season_source', 8); // auto | owner
            $table->decimal('list_price', 14, 2);
            $table->decimal('unit_cost', 14, 2);
            $table->json('contains'); // FoodClass value => summed qty, food classes only
            $table->string('main_protein', 16)->nullable();
            $table->boolean('has_recipe');
            $table->boolean('is_existing_combo');
            $table->json('combo_members')->nullable(); // list of item keys
            $table->timestamp('built_at')->useCurrent(); // explicit default, so MariaDB adds no ON UPDATE
            $table->timestamps();

            $table->unique(['organization_id', 'item_key'], 'mi_profile_unique');
        });

        Schema::create('item_period_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 16);
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->string('channel', 16)->default('all');
            $table->string('item_key', 64);
            $table->decimal('qty', 14, 3);
            $table->decimal('net_sales', 14, 2);
            $table->decimal('cogs', 14, 2);
            $table->decimal('gross_profit', 14, 2);
            $table->unsignedSmallInteger('open_days');
            $table->unsignedSmallInteger('days_sold');
            $table->decimal('velocity', 14, 4);
            $table->unsignedInteger('orders');
            $table->decimal('attach_rate', 5, 4)->nullable();
            $table->json('daypart');
            $table->json('weekday');
            $table->json('channel_mix')->nullable();
            $table->decimal('repeat_customer_share', 5, 4)->nullable();
            $table->decimal('return_qty', 14, 3)->default(0);
            $table->decimal('waste_cost', 14, 2)->default(0);
            $table->decimal('mom_velocity_change', 10, 4)->nullable();
            $table->string('build_id', 26);
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'period_key', 'branch_id', 'channel', 'item_key'], 'mi_stats_unique');
        });

        Schema::create('item_affinities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 16);
            $table->string('channel', 16);
            $table->unsignedTinyInteger('size');
            $table->char('itemset_hash', 40); // sha1 of the sorted item keys joined with '|'
            $table->json('items');
            $table->json('member_orders');
            $table->unsignedInteger('together_orders');
            $table->unsignedInteger('total_orders');
            $table->decimal('support', 10, 8);
            $table->decimal('lift', 12, 4);
            $table->decimal('avg_order_value', 14, 2);
            $table->string('build_id', 26);
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'period_key', 'channel', 'itemset_hash'], 'mi_affinity_unique');
            $table->index(['organization_id', 'period_key', 'channel', 'size'], 'mi_affinity_lookup');
        });

        Schema::create('item_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 16);
            $table->string('strategy', 32);
            $table->string('channel', 16)->default('all');
            $table->string('segment_key', 32); // <role>:<price_tier>
            $table->string('item_key', 64);
            $table->decimal('score', 8, 4);
            $table->unsignedSmallInteger('rank'); // 1-based within strategy + channel + segment
            $table->string('quadrant', 16);
            $table->json('breakdown'); // factor => {value, weight, contribution}
            $table->string('build_id', 26);
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'period_key', 'strategy', 'channel', 'item_key'], 'mi_rank_unique');
            $table->index(['organization_id', 'period_key', 'strategy', 'channel', 'segment_key', 'rank'], 'mi_rank_lookup');
        });

        Schema::create('combo_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 16);
            $table->string('template', 16);
            $table->string('channel', 16)->default('all');
            $table->string('hero_key', 64);
            $table->char('signature', 40);
            $table->json('items'); // list of {key, name, role, qty, list_price}
            $table->decimal('list_total', 14, 2);
            $table->decimal('cost_total', 14, 2);
            $table->decimal('suggested_price', 14, 2);
            $table->decimal('margin_pct', 6, 4);
            $table->decimal('discount_pct', 6, 4);
            $table->json('evidence');
            $table->json('checks');
            $table->string('overlaps_combo_key', 64)->nullable();
            $table->decimal('score', 8, 4);
            $table->string('status', 10)->default('candidate'); // candidate | approved | rejected
            $table->text('owner_note')->nullable();
            $table->string('build_id', 26);
            $table->timestamps();

            $table->unique(['organization_id', 'period_key', 'channel', 'signature'], 'mi_combo_unique');
            $table->index(['organization_id', 'period_key', 'template', 'hero_key'], 'mi_combo_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('combo_candidates');
        Schema::dropIfExists('item_rankings');
        Schema::dropIfExists('item_affinities');
        Schema::dropIfExists('item_period_stats');
        Schema::dropIfExists('item_profiles');
        Schema::dropIfExists('category_profiles');
        Schema::dropIfExists('component_classes');
    }
};
