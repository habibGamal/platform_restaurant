<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Domain\Menu\Intelligence\BundlePricer;
use App\Domain\Menu\Intelligence\ComboCandidateGenerator;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Menu\ComboCandidate;
use App\Models\Organization;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * October 2026, seeded straight into the intelligence tables:
 *
 *   heroes   BURGER main single (meat, bread), push 0.60; LOSS main single, cost 90 of 100, push 0.50;
 *            TRAY main family serves 4 (chicken, rice ×2, salad), push 0.80
 *   floors   main:single (2500 + 100) / 6000 = 0.4333; main:family 4000 / 8000 = 0.5
 *   sides    RICESIDE (rice), SALADSIDE (salad), SOUP (soup), FRIES (vegetable); drinks COLA, JUICE (no recipe)
 *   baskets  BURGER+FRIES 40, BURGER+COLA 50, BURGER+COLA+FRIES 30 (lift 2.4);
 *            TRAY+RICESIDE 50, TRAY+SALADSIDE 40, TRAY+FRIES 30, TRAY+SOUP 3 (lift 9, below min support)
 *   existing combo MEAL (role combo)
 */
class ComboCandidateGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-08';

    private const PERIOD = '2026-10';

    private const BUILD = '01JCOMBO00000000000000001';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Org Combos',
            'slug' => 'org-combos-'.Str::random(6),
            'plan' => 'pro',
            'ai_enabled' => true,
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);

        $this->profile('BURGER', 'main', 'single', 1, 100, 40, ['meat' => 1, 'bread' => 1]);
        $this->profile('LOSS', 'main', 'single', 1, 100, 90, ['chicken' => 1]);
        $this->profile('TRAY', 'main', 'family', 4, 400, 160, ['chicken' => 1, 'rice' => 2, 'salad' => 1]);
        $this->profile('RICESIDE', 'side', 'single', 1, 30, 9, ['rice' => 1]);
        $this->profile('SALADSIDE', 'side', 'single', 1, 30, 9, ['salad' => 1]);
        $this->profile('SOUP', 'side', 'single', 1, 50, 15, ['soup' => 1]);
        $this->profile('FRIES', 'side', 'single', 1, 30, 9, ['vegetable' => 1]);
        $this->profile('COLA', 'drink', 'single', 1, 20, 5, []);
        $this->profile('JUICE', 'drink', 'single', 1, 25, 8, []);
        $this->profile('MEAL', 'combo', 'single', 1, 120, 50, ['meat' => 1, 'bread' => 1, 'vegetable' => 1], combo: true);

        $this->stat('BURGER', 50, 5000, 2500, orders: 100);
        $this->stat('LOSS', 10, 1000, 900, orders: 10);
        $this->stat('TRAY', 20, 8000, 4000, orders: 100);
        foreach (['RICESIDE' => 80, 'SALADSIDE' => 70, 'SOUP' => 20, 'FRIES' => 60, 'COLA' => 200, 'JUICE' => 50] as $key => $orders) {
            $this->stat($key, $orders, $orders * 30, $orders * 9, orders: $orders);
        }

        $this->ranking('BURGER', 'main:single', 0.6);
        $this->ranking('LOSS', 'main:single', 0.5);
        $this->ranking('TRAY', 'main:family', 0.8);

        $this->itemset(['BURGER' => 100, 'FRIES' => 90], 40, 1.5);
        $this->itemset(['BURGER' => 100, 'COLA' => 250], 50, 1.2);
        $this->itemset(['BURGER' => 100, 'COLA' => 250, 'FRIES' => 90], 30, 2.4);
        $this->itemset(['TRAY' => 100, 'RICESIDE' => 120], 50, 2.0);
        $this->itemset(['TRAY' => 100, 'SALADSIDE' => 110], 40, 1.8);
        $this->itemset(['TRAY' => 100, 'FRIES' => 90], 30, 1.6);
        $this->itemset(['TRAY' => 100, 'SOUP' => 20], 3, 9.0);
    }

    public function test_family_feast_drops_redundant_sides_prefers_soup_and_matches_drinks_to_serves(): void
    {
        $this->build();

        $feasts = $this->candidates('family_feast');

        // RICESIDE and SALADSIDE have the strongest affinity, but the tray already holds rice and salad.
        $this->assertSame([['TRAY', 'COLA', 'SOUP'], ['TRAY', 'COLA', 'FRIES']], $feasts->map(fn (ComboCandidate $c): array => array_column($c->items, 'key'))->all());

        $soup = $feasts->first();
        $this->assertNotNull($soup);
        $this->assertSame('TRAY', $soup->hero_key);
        $this->assertSame([
            ['key' => 'TRAY', 'name' => 'Item TRAY', 'role' => 'main', 'qty' => 1, 'list_price' => 400.0],
            ['key' => 'COLA', 'name' => 'Item COLA', 'role' => 'drink', 'qty' => 4, 'list_price' => 20.0],
            ['key' => 'SOUP', 'name' => 'Item SOUP', 'role' => 'side', 'qty' => 1, 'list_price' => 50.0],
        ], $soup->items);
        $this->assertSame(['redundant_rejected' => ['RICESIDE', 'SALADSIDE'], 'unknown_recipe' => [], 'drinks_match_serves' => true, 'floor' => 0.5], $soup->checks);

        // 400 + 4 × 20 + 50 = 530 list, 195 cost; 15% off rounds down to 450.
        $this->assertSame([530.0, 195.0, 450.0, 0.5667, 0.1509], [$soup->list_total, $soup->cost_total, $soup->suggested_price, $soup->margin_pct, $soup->discount_pct]);
        $this->assertNull($soup->overlaps_combo_key);
        $this->assertSame('candidate', $soup->status);
        $this->assertSame(self::BUILD, $soup->build_id);
        $this->assertSame(sha1('family_feast|all|COLA:4|SOUP:1|TRAY:1'), $soup->signature);
    }

    public function test_solo_meal_picks_a_side_and_a_drink_for_a_single_main(): void
    {
        DB::table('item_profiles')->where('item_key', 'SALADSIDE')->update(['contains' => '{}', 'has_recipe' => false]);

        $this->build();

        $solos = $this->candidates('solo_meal');

        // FRIES is paired with BURGER; the other sides follow on their own orders. LOSS never prices.
        $this->assertSame([
            ['BURGER', 'FRIES', 'COLA'],
            ['BURGER', 'RICESIDE', 'COLA'],
            ['BURGER', 'SALADSIDE', 'COLA'],
        ], $solos->map(fn (ComboCandidate $c): array => array_column($c->items, 'key'))->all());
        $this->assertSame([1, 1, 1], array_column($solos->first()->items, 'qty'));
        $this->assertSame(['redundant_rejected' => [], 'unknown_recipe' => [], 'drinks_match_serves' => true, 'floor' => 0.4333], $solos->first()->checks);
        // A side without a recipe is allowed, and flagged.
        $this->assertSame(['SALADSIDE'], $solos[2]->checks['unknown_recipe']);
        $this->assertSame([], $this->candidates('sharing_box')->all());
    }

    public function test_evidence_is_observed_only_for_a_trusted_itemset_of_the_candidate(): void
    {
        $this->build();

        $burger = $this->candidate('solo_meal', ['BURGER', 'FRIES', 'COLA']);
        $this->assertSame('observed', $burger->evidence['type']);
        $this->assertSame(['BURGER', 'COLA', 'FRIES'], $burger->evidence['itemset']);
        $this->assertSame(30, $burger->evidence['together_orders']);
        $this->assertSame(2.4, $burger->evidence['lift']);
        $this->assertStringContainsString('30 طلب', $burger->evidence['why']);
        // 0.5 × 0.6 + 0.3 × (2.4 / 3) + 0.2 × (0.568 / 0.6)
        $this->assertSame(0.7293, $burger->score);

        // Only the BURGER+COLA pair is inside this one.
        $rice = $this->candidate('solo_meal', ['BURGER', 'RICESIDE', 'COLA']);
        $this->assertSame(['BURGER', 'COLA'], $rice->evidence['itemset']);

        // TRAY+SOUP has lift 9 but only 3 orders: rule-based.
        $soup = $this->candidate('family_feast', ['TRAY', 'COLA', 'SOUP']);
        $this->assertSame('rule', $soup->evidence['type']);
        $this->assertArrayNotHasKey('lift', $soup->evidence);
        $this->assertSame(round(0.5 * 0.8 + 0.3 * 0.2 + 0.2 * (0.5667 / 0.6), 4), $soup->score);

        $fries = $this->candidate('family_feast', ['TRAY', 'COLA', 'FRIES']);
        $this->assertSame(['FRIES', 'TRAY'], $fries->evidence['itemset']);
    }

    public function test_overlap_with_an_existing_combo_sets_the_key_and_halves_the_score(): void
    {
        $this->seedRecipes();

        $this->build();

        // BURGER (BEEF, BUN, unclassified MYSTERY) + FRIES (POTATO) matches MEAL (BEEF, BUN, POTATO) once MYSTERY is ignored.
        $burger = $this->candidate('solo_meal', ['BURGER', 'FRIES', 'COLA']);
        $this->assertSame('MEAL', $burger->overlaps_combo_key);
        $this->assertSame(round(0.729333 / 2, 4), $burger->score);

        // BEEF, BUN against BEEF, BUN, POTATO: 2/3 is below 0.80.
        $rice = $this->candidate('solo_meal', ['BURGER', 'RICESIDE', 'COLA']);
        $this->assertNull($rice->overlaps_combo_key);
    }

    public function test_an_owner_decision_survives_a_rebuild_through_the_signature(): void
    {
        $this->build();
        $burger = $this->candidate('solo_meal', ['BURGER', 'FRIES', 'COLA']);
        DB::table('combo_candidates')->where('id', $burger->id)->update(['status' => 'approved', 'owner_note' => 'نزلها الشهر الجاي']);

        $this->assertSame(5, $this->build());

        $rebuilt = $this->candidate('solo_meal', ['BURGER', 'FRIES', 'COLA']);
        $this->assertNotSame($burger->id, $rebuilt->id);
        $this->assertSame('approved', $rebuilt->status);
        $this->assertSame('نزلها الشهر الجاي', $rebuilt->owner_note);
        $this->assertSame(1, ComboCandidate::withoutGlobalScopes()->where('organization_id', $this->organization->id)->where('status', 'approved')->count());
    }

    public function test_a_candidate_below_the_margin_floor_is_dropped(): void
    {
        $this->build();

        // LOSS + side + drink: 150 list, 104 cost; even 5% off leaves 27% against a 43% floor.
        $this->assertNull(BundlePricer::price(150, 104, 0.4333));
        $this->assertSame(0, ComboCandidate::withoutGlobalScopes()->where('organization_id', $this->organization->id)->where('hero_key', 'LOSS')->count());
    }

    public function test_a_loss_making_segment_floors_at_zero_margin(): void
    {
        // main:single now loses money: (5000 − 6000 + 1000 − 1100) / 6000 = −0.1833.
        DB::table('item_period_stats')->where('organization_id', $this->organization->id)->where('item_key', 'BURGER')->update(['cogs' => 6000, 'gross_profit' => -1000]);
        DB::table('item_period_stats')->where('organization_id', $this->organization->id)->where('item_key', 'LOSS')->update(['cogs' => 1100, 'gross_profit' => -100]);

        $this->build();

        $singles = ComboCandidate::withoutGlobalScopes()->where('organization_id', $this->organization->id)->whereIn('hero_key', ['BURGER', 'LOSS'])->get();
        $this->assertNotEmpty($singles);
        foreach ($singles as $combo) {
            $this->assertSame(0.0, (float) $combo->checks['floor'], $combo->hero_key);
            $this->assertGreaterThan(0.0, $combo->margin_pct, $combo->hero_key);
            $this->assertGreaterThan($combo->cost_total, $combo->suggested_price, $combo->hero_key);
        }
    }

    public function test_channels_with_itemsets_get_their_own_candidates(): void
    {
        $this->itemset(['BURGER' => 40, 'FRIES' => 30], 20, 1.4, 'delivery');
        $this->ranking('BURGER', 'main:single', 0.7, 'delivery');
        $this->stat('COLA', 30, 600, 150, orders: 30, channel: 'delivery');

        $this->assertSame(6, $this->build());

        // Only FRIES (paired) and COLA (sold) exist on delivery.
        $delivery = ComboCandidate::withoutGlobalScopes()->where('organization_id', $this->organization->id)->where('channel', 'delivery')->get();
        $this->assertCount(1, $delivery);
        $this->assertSame(['BURGER', 'FRIES', 'COLA'], array_column($delivery[0]->items, 'key'));
        $this->assertSame(0.4333, $delivery[0]->checks['floor']);
    }

    private function build(): int
    {
        return app(ComboCandidateGenerator::class)->build($this->organization, self::PERIOD, CarbonImmutable::parse(self::TODAY), self::BUILD);
    }

    /**
     * @return Collection<int, ComboCandidate>
     */
    private function candidates(string $template): Collection
    {
        return ComboCandidate::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('period_key', self::PERIOD)
            ->where('channel', 'all')
            ->where('template', $template)
            ->orderBy('id')
            ->get()
            ->values();
    }

    /** @param  list<string>  $keys  in item order */
    private function candidate(string $template, array $keys): ComboCandidate
    {
        $match = $this->candidates($template)->first(fn (ComboCandidate $c): bool => array_column($c->items, 'key') === $keys);
        $this->assertNotNull($match, implode('+', $keys));

        return $match;
    }

    /** @param  array<string, float>  $contains */
    private function profile(string $key, string $role, string $tier, int $serves, float $price, float $cost, array $contains, bool $combo = false): void
    {
        DB::table('item_profiles')->insert([
            'organization_id' => $this->organization->id,
            'item_key' => $key,
            'name' => 'Item '.$key,
            'category_key' => null,
            'role' => $role,
            'serves_n' => $serves,
            'serves_source' => 'default',
            'price_tier' => $tier,
            'price_tier_source' => 'auto',
            'season' => 'all',
            'season_source' => 'auto',
            'list_price' => $price,
            'unit_cost' => $cost,
            'contains' => json_encode((object) $contains),
            'main_protein' => null,
            'has_recipe' => $contains !== [],
            'is_existing_combo' => $combo,
            'combo_members' => null,
            'built_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stat(string $key, float $qty, float $net, float $cogs, int $orders, string $channel = 'all'): void
    {
        DB::table('item_period_stats')->insert([
            'organization_id' => $this->organization->id,
            'period_key' => self::PERIOD,
            'branch_id' => 0,
            'channel' => $channel,
            'item_key' => $key,
            'qty' => $qty,
            'net_sales' => $net,
            'cogs' => $cogs,
            'gross_profit' => $net - $cogs,
            'open_days' => 31,
            'days_sold' => 20,
            'velocity' => $qty / 20,
            'orders' => $orders,
            'attach_rate' => null,
            'daypart' => '{}',
            'weekday' => '{}',
            'channel_mix' => null,
            'repeat_customer_share' => null,
            'return_qty' => 0,
            'waste_cost' => 0,
            'mom_velocity_change' => null,
            'build_id' => self::BUILD,
            'created_at' => now(),
        ]);
    }

    private function ranking(string $key, string $segment, float $score, string $channel = 'all'): void
    {
        DB::table('item_rankings')->insert([
            'organization_id' => $this->organization->id,
            'period_key' => self::PERIOD,
            'strategy' => 'push_next_month',
            'channel' => $channel,
            'segment_key' => $segment,
            'item_key' => $key,
            'score' => $score,
            'rank' => 1,
            'quadrant' => 'stars',
            'breakdown' => '{}',
            'build_id' => self::BUILD,
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, int>  $members  item key => orders containing it */
    private function itemset(array $members, int $together, float $lift, string $channel = 'all'): void
    {
        ksort($members, SORT_STRING);
        $items = array_keys($members);

        DB::table('item_affinities')->insert([
            'organization_id' => $this->organization->id,
            'period_key' => self::PERIOD,
            'channel' => $channel,
            'size' => count($items),
            'itemset_hash' => sha1(implode('|', $items)),
            'items' => json_encode($items),
            'member_orders' => json_encode($members),
            'together_orders' => $together,
            'total_orders' => 1000,
            'support' => $together / 1000,
            'lift' => $lift,
            'avg_order_value' => 150,
            'build_id' => self::BUILD,
            'created_at' => now(),
        ]);
    }

    /** Recipes for the overlap check: BURGER, FRIES and the existing MEAL combo. */
    private function seedRecipes(): void
    {
        $restaurant = Restaurant::create(['organization_id' => $this->organization->id, 'name' => 'R']);
        $branch = Branch::create([
            'organization_id' => $this->organization->id,
            'restaurant_id' => $restaurant->id,
            'name' => 'Branch',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-01-01',
            'status' => BranchStatus::ACTIVE,
        ])->id;

        // MYSTERY is below the trust threshold, so it counts as `other`.
        $components = ['BEEF' => ['meat', 'owner', 1.0], 'BUN' => ['bread', 'owner', 1.0], 'POTATO' => ['vegetable', 'owner', 1.0], 'MYSTERY' => ['meat', 'auto', 0.5]];
        foreach ($components as $ref => [$class, $source, $confidence]) {
            $this->product($branch, "comp-{$ref}", $ref, 'raw_material');
            DB::table('component_classes')->insert([
                'organization_id' => $this->organization->id,
                'component_key' => $ref,
                'name' => $ref,
                'class' => $class,
                'source' => $source,
                'confidence' => $confidence,
                'classified_at' => now(),
            ]);
        }

        foreach (['BURGER' => ['BEEF', 'BUN', 'MYSTERY'], 'FRIES' => ['POTATO'], 'MEAL' => ['BEEF', 'BUN', 'POTATO']] as $ref => $lines) {
            $this->product($branch, "p-{$ref}", $ref, 'manufactured');
            foreach ($lines as $component) {
                DB::table('dim_recipes')->insert([
                    'organization_id' => $this->organization->id,
                    'branch_id' => $branch,
                    'product_uuid' => "p-{$ref}",
                    'component_uuid' => "comp-{$component}",
                    'quantity' => 1,
                    'source_version' => 1,
                ]);
            }
        }
    }

    private function product(int $branch, string $uuid, string $ref, string $type): void
    {
        DB::table('dim_products')->insert([
            'organization_id' => $this->organization->id,
            'branch_id' => $branch,
            'uuid' => $uuid,
            'source_version' => 1,
            'name' => $ref,
            'product_ref' => $ref,
            'category_uuid' => null,
            'type' => $type,
            'price' => 0,
            'cost' => 0,
            'deleted_at' => null,
        ]);
    }
}
