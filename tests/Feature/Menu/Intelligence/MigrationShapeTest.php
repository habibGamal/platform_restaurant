<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MigrationShapeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Minimal valid row per table, without organization_id. Sentinel columns are left to their defaults.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function tables(): array
    {
        $now = '2026-11-07 03:00:00';
        $build = '01JBUILD0000000000000000AA';

        return [
            'component_classes' => ['component_classes', [
                'component_key' => 'RAW-1', 'name' => 'Chicken breast', 'class' => 'chicken',
                'source' => 'auto', 'confidence' => 0.9, 'classified_at' => $now,
            ]],
            'category_profiles' => ['category_profiles', [
                'category_key' => 'mains', 'name' => 'Mains', 'source' => 'rule', 'confidence' => 0.8, 'classified_at' => $now,
            ]],
            'item_profiles' => ['item_profiles', [
                'item_key' => 'K1', 'name' => 'Kelto Wrap', 'serves_source' => 'default', 'price_tier' => 'single',
                'price_tier_source' => 'auto', 'season_source' => 'auto', 'list_price' => 100, 'unit_cost' => 28,
                'contains' => '{}', 'has_recipe' => false, 'is_existing_combo' => false, 'built_at' => $now,
            ]],
            'item_period_stats' => ['item_period_stats', [
                'period_key' => '2026-10', 'item_key' => 'K1', 'qty' => 1, 'net_sales' => 100, 'cogs' => 28,
                'gross_profit' => 72, 'open_days' => 31, 'days_sold' => 1, 'velocity' => 0.0323, 'orders' => 1,
                'daypart' => '{}', 'weekday' => '{}', 'build_id' => $build,
            ]],
            'item_affinities' => ['item_affinities', [
                'period_key' => '2026-10', 'channel' => 'all', 'size' => 2, 'itemset_hash' => sha1('K1|K2'),
                'items' => '["K1","K2"]', 'member_orders' => '{"K1":10,"K2":8}', 'together_orders' => 5,
                'total_orders' => 400, 'support' => 0.0125, 'lift' => 25, 'avg_order_value' => 140, 'build_id' => $build,
            ]],
            'item_rankings' => ['item_rankings', [
                'period_key' => '2026-10', 'strategy' => 'protect', 'segment_key' => 'main:single', 'item_key' => 'K1',
                'score' => 0.75, 'rank' => 1, 'quadrant' => 'stars', 'breakdown' => '{}', 'build_id' => $build,
            ]],
            'combo_candidates' => ['combo_candidates', [
                'period_key' => '2026-10', 'template' => 'solo_meal', 'hero_key' => 'K1', 'signature' => sha1('solo_meal|K1|K2'),
                'items' => '[]', 'list_total' => 130, 'cost_total' => 40, 'suggested_price' => 115, 'margin_pct' => 0.65,
                'discount_pct' => 0.115, 'evidence' => '{}', 'checks' => '{}', 'score' => 0.5, 'build_id' => $build,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('tables')]
    public function test_table_exists_with_defaults_and_its_unique_key_rejects_duplicates(string $table, array $row): void
    {
        $this->assertTrue(Schema::hasTable($table));

        $org = Organization::factory()->create();
        $row = ['organization_id' => $org->id] + $row;

        DB::table($table)->insert($row);

        $stored = (array) DB::table($table)->where('organization_id', $org->id)->first();
        $defaults = ['branch_id' => 0, 'channel' => 'all', 'season' => 'all', 'meal_type' => 'single', 'serves_n' => 1, 'status' => 'candidate'];
        foreach (array_diff_key(array_intersect_key($defaults, $stored), $row) as $column => $expected) {
            $this->assertEquals($expected, $stored[$column], "{$table}.{$column} default");
        }

        // Same org, same unique key: rejected. Another org with the same key: allowed.
        DB::table($table)->insert(['organization_id' => Organization::factory()->create()->id] + $row);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table($table)->insert($row);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stampedColumns(): array
    {
        return [
            'component_classes.classified_at' => ['component_classes', 'classified_at'],
            'category_profiles.classified_at' => ['category_profiles', 'classified_at'],
            'item_profiles.built_at' => ['item_profiles', 'built_at'],
        ];
    }

    // MariaDB gives a NOT NULL timestamp without a default ON UPDATE CURRENT_TIMESTAMP; an owner edit must not touch it.
    #[DataProvider('stampedColumns')]
    public function test_updating_other_columns_leaves_the_timestamp_unchanged(string $table, string $column): void
    {
        $org = Organization::factory()->create();
        DB::table($table)->insert(['organization_id' => $org->id] + self::tables()[$table][1]);

        DB::table($table)->where('organization_id', $org->id)->update(['name' => 'Edited by owner']);

        $this->assertSame('2026-11-07 03:00:00', DB::table($table)->where('organization_id', $org->id)->value($column));
    }

    public function test_rows_are_deleted_with_their_organization(): void
    {
        $org = Organization::factory()->create();
        DB::table('item_rankings')->insert(['organization_id' => $org->id] + self::tables()['item_rankings'][1]);

        $org->delete();

        $this->assertSame(0, DB::table('item_rankings')->count());
    }
}
