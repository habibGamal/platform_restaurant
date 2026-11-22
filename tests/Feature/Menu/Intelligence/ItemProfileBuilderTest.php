<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Domain\Menu\Intelligence\ItemProfileBuilder;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Menu\ItemProfile;
use App\Models\Organization;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemProfileBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-07';

    private Organization $organization;

    private int $branchA;

    private int $branchB;

    /** Recipe components on both branches: ref => [name, class, source, confidence]. */
    private const COMPONENTS = [
        'FORK' => ['Fork', 'cutlery', 'rule', 0.95],
        'SPOON' => ['Spoon', 'cutlery', 'rule', 0.95],
        'BAG' => ['Bag', 'packaging', 'rule', 0.95],
        'RICE' => ['Rice', 'rice', 'auto', 0.90],
        'SALAD' => ['Salad', 'salad', 'auto', 0.80],
        'BREAD' => ['Bread', 'bread', 'owner', 1.00],
        'CHICKEN' => ['Chicken', 'chicken', 'auto', 0.85],
        'BEEF' => ['Beef', 'meat', 'auto', 0.90],
        // Below the trust threshold, so it counts as `other`, not meat.
        'MYSTERY' => ['Mystery Meat', 'meat', 'auto', 0.50],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Org Profiles',
            'slug' => 'org-profiles-'.Str::random(6),
            'plan' => 'pro',
            'ai_enabled' => true,
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);
        $restaurant = Restaurant::create(['organization_id' => $this->organization->id, 'name' => 'R']);
        foreach (['A', 'B'] as $letter) {
            $this->{'branch'.$letter} = Branch::create([
                'organization_id' => $this->organization->id,
                'restaurant_id' => $restaurant->id,
                'name' => 'Branch '.$letter,
                'timezone' => 'Africa/Cairo',
                'opened_on' => '2026-01-01',
                'status' => BranchStatus::ACTIVE,
            ])->id;
        }
    }

    private function seedComponents(): void
    {
        foreach ([$this->branchA, $this->branchB] as $branch) {
            foreach (self::COMPONENTS as $ref => [$name]) {
                $this->product($branch, "comp-{$ref}-{$branch}", $name, $ref, null, 'raw_material', 0);
            }
        }

        foreach (self::COMPONENTS as $ref => [$name, $class, $source, $confidence]) {
            DB::table('component_classes')->insert([
                'organization_id' => $this->organization->id,
                'component_key' => $ref,
                'name' => $name,
                'class' => $class,
                'source' => $source,
                'confidence' => $confidence,
                'classified_at' => now(),
            ]);
        }
    }

    private function category(int $branch, string $uuid, string $name): void
    {
        DB::table('dim_categories')->insert([
            'organization_id' => $this->organization->id,
            'branch_id' => $branch,
            'uuid' => $uuid,
            'source_version' => 1,
            'name' => $name,
        ]);
    }

    private function categoryProfile(string $key, string $season, string $mealType, string $source, float $confidence): void
    {
        DB::table('category_profiles')->insert([
            'organization_id' => $this->organization->id,
            'category_key' => $key,
            'name' => $key,
            'season' => $season,
            'meal_type' => $mealType,
            'source' => $source,
            'confidence' => $confidence,
            'classified_at' => now(),
        ]);
    }

    private function product(int $branch, string $uuid, string $name, ?string $ref, ?string $category, string $type, float $price, float $cost = 0, bool $deleted = false): void
    {
        DB::table('dim_products')->insert([
            'organization_id' => $this->organization->id,
            'branch_id' => $branch,
            'uuid' => $uuid,
            'source_version' => 1,
            'name' => $name,
            'product_ref' => $ref,
            'category_uuid' => $category,
            'type' => $type,
            'price' => $price,
            'cost' => $cost,
            'deleted_at' => $deleted ? now() : null,
        ]);
    }

    /** @param  array<string, float>  $lines  component ref => quantity */
    private function recipe(int $branch, string $productUuid, array $lines, int $version = 1): void
    {
        foreach ($lines as $ref => $qty) {
            DB::table('dim_recipes')->insert([
                'organization_id' => $this->organization->id,
                'branch_id' => $branch,
                'product_uuid' => $productUuid,
                'component_uuid' => "comp-{$ref}-{$branch}",
                'quantity' => $qty,
                'source_version' => $version,
            ]);
        }
    }

    private function sold(int $branch, string $productUuid, string $ref, float $qty, float $net, float $cogs, string $date = '2026-11-01'): void
    {
        DB::table('agg_product_daily')->insert([
            'organization_id' => $this->organization->id,
            'branch_id' => $branch,
            'business_date' => $date,
            'product_uuid' => $productUuid,
            'product_ref' => $ref,
            'qty_sold' => $qty,
            'net_sales' => $net,
            'cogs' => $cogs,
        ]);
    }

    private function build(): int
    {
        return app(ItemProfileBuilder::class)->build($this->organization, CarbonImmutable::parse(self::TODAY));
    }

    private function profile(string $key): ItemProfile
    {
        return ItemProfile::withoutGlobalScopes()
            ->where('organization_id', $this->organization->id)
            ->where('item_key', $key)
            ->firstOrFail();
    }

    public function test_a_tray_recipe_gives_serves_contains_and_protein(): void
    {
        $this->seedComponents();
        $this->category($this->branchA, 'cat-trays', '  Family   Trays ');
        $this->product($this->branchA, 'tray-a', 'Family Tray', 'TRAY', 'cat-trays', 'manufactured', 600);
        $this->product($this->branchB, 'tray-b', 'Tray B Name', 'TRAY', null, 'manufactured', 0, 300);
        $this->product($this->branchB, 'tray-old', 'Old Tray', 'TRAY', null, 'manufactured', 900, 0, deleted: true);
        $this->recipe($this->branchA, 'tray-a', [
            'FORK' => 4, 'SPOON' => 4, 'BAG' => 1,
            'RICE' => 0.75, 'SALAD' => 0.3, 'BREAD' => 2, 'CHICKEN' => 1.2, 'MYSTERY' => 2,
        ]);
        $this->sold($this->branchA, 'tray-a', 'TRAY', 10, 6000, 2500);
        // Sold more than 180 days ago, with no recipe: not profiled.
        $this->product($this->branchA, 'old-a', 'Old Item', 'OLD', null, 'manufactured', 50);
        $this->sold($this->branchA, 'old-a', 'OLD', 3, 150, 60, '2026-04-01');

        $this->assertSame(1, $this->build());

        $tray = $this->profile('TRAY');
        $this->assertSame(['Family Tray', 'family trays'], [$tray->name, $tray->category_key]);
        $this->assertSame([4, 'recipe', 'family', 'auto'], [$tray->serves_n, $tray->serves_source, $tray->price_tier, $tray->price_tier_source]);
        $this->assertEquals(['rice' => 0.75, 'salad' => 0.3, 'bread' => 2.0, 'chicken' => 1.2, 'other' => 2.0], $tray->contains);
        $this->assertArrayNotHasKey('cutlery', $tray->contains);
        $this->assertArrayNotHasKey('packaging', $tray->contains);
        // The untrusted "meat" component counts as other, so chicken is the main protein.
        $this->assertSame('chicken', $tray->main_protein);
        $this->assertSame(600.0, $tray->list_price);
        $this->assertSame(250.0, $tray->unit_cost);
        $this->assertTrue($tray->has_recipe);
        $this->assertFalse($tray->is_existing_combo);
        $this->assertNull($tray->combo_members);
        $this->assertSame(['all', 'auto'], [$tray->season, $tray->season_source]);
    }

    public function test_a_family_category_sets_serves_without_a_recipe(): void
    {
        $this->category($this->branchA, 'cat-family', 'Family Meals');
        $this->category($this->branchA, 'cat-snacks', 'Snacks');
        $this->categoryProfile('family meals', 'ramadan', 'family', 'auto', 0.80);
        $this->categoryProfile('snacks', 'winter', 'sharing', 'auto', 0.50);
        $this->product($this->branchA, 'feast-a', 'Feast', 'FEAST', 'cat-family', 'manufactured', 400, 150);
        $this->product($this->branchA, 'snack-a', 'Snack', 'SNACK', 'cat-snacks', 'manufactured', 40, 12);
        $this->product($this->branchB, 'snack-b', 'Snack', 'SNACK', null, 'manufactured', 50, 18);
        $this->sold($this->branchA, 'feast-a', 'FEAST', 4, 1600, 600);
        $this->sold($this->branchA, 'snack-a', 'SNACK', 5, 200, 0);

        $this->assertSame(2, $this->build());

        $feast = $this->profile('FEAST');
        $this->assertSame([4, 'category', 'family', 'ramadan'], [$feast->serves_n, $feast->serves_source, $feast->price_tier, $feast->season]);
        $this->assertSame([], $feast->contains);
        $this->assertSame('{}', DB::table('item_profiles')->where('id', $feast->id)->value('contains'));
        $this->assertFalse($feast->has_recipe);

        // An untrusted category profile gives no signal at all.
        $snack = $this->profile('SNACK');
        $this->assertSame([1, 'default', 'single', 'all'], [$snack->serves_n, $snack->serves_source, $snack->price_tier, $snack->season]);
        // No sales cogs: the average non-zero dim cost stands in.
        $this->assertSame(15.0, $snack->unit_cost);
        $this->assertSame(45.0, $snack->list_price);
    }

    public function test_a_price_far_above_the_role_median_sets_the_tier(): void
    {
        $roles = app(ItemRoles::class);
        foreach (['M1' => 100, 'M2' => 100, 'M3' => 100, 'MID' => 250, 'BIG' => 500] as $ref => $price) {
            $this->product($this->branchA, "p-{$ref}", "Item {$ref}", $ref, null, 'manufactured', $price);
            $this->sold($this->branchA, "p-{$ref}", $ref, 5, 5 * $price, 0);
            $roles->setOwnerRole($this->organization->id, $ref, ItemRole::Main);
        }

        $this->assertSame(5, $this->build());

        $big = $this->profile('BIG');
        $this->assertSame([1, 'default', 'family', 'main'], [$big->serves_n, $big->serves_source, $big->price_tier, $big->role]);
        $this->assertSame('sharing', $this->profile('MID')->price_tier);
        $this->assertSame('single', $this->profile('M1')->price_tier);
    }

    public function test_an_owner_set_serves_n_survives_a_rebuild(): void
    {
        $this->product($this->branchA, 'plain-a', 'Plain', 'PLAIN', null, 'manufactured', 80);
        $this->sold($this->branchA, 'plain-a', 'PLAIN', 5, 400, 100);
        $this->build();
        $this->assertSame([1, 'default', 'single'], [$this->profile('PLAIN')->serves_n, $this->profile('PLAIN')->serves_source, $this->profile('PLAIN')->price_tier]);

        DB::table('item_profiles')->where('organization_id', $this->organization->id)->where('item_key', 'PLAIN')
            ->update(['serves_n' => 6, 'serves_source' => 'owner']);
        DB::table('dim_products')->where('uuid', 'plain-a')->update(['price' => 90]);

        $this->assertSame(1, $this->build());

        $plain = $this->profile('PLAIN');
        $this->assertSame([6, 'owner'], [$plain->serves_n, $plain->serves_source]);
        // The tier follows the owner's answer, and the rest of the card is rebuilt.
        $this->assertSame('family', $plain->price_tier);
        $this->assertSame(90.0, $plain->list_price);
    }

    public function test_combo_members_are_items_whose_food_sits_inside_the_combo(): void
    {
        $this->seedComponents();
        $a = $this->branchA;
        foreach (['COMBO-BOX' => 150, 'SANDWICH' => 60, 'BOWL' => 70] as $ref => $price) {
            $this->product($a, "p-{$ref}", $ref, $ref, null, 'manufactured', $price);
        }
        $this->product($a, 'p-SODA', 'Soda', 'SODA', null, 'manufactured', 15);
        $this->sold($a, 'p-SODA', 'SODA', 9, 135, 30);
        $this->recipe($a, 'p-COMBO-BOX', ['CHICKEN' => 1, 'BREAD' => 1, 'RICE' => 1, 'FORK' => 1, 'BAG' => 1]);
        $this->recipe($a, 'p-SANDWICH', ['CHICKEN' => 0.5, 'BREAD' => 1, 'BAG' => 1]);
        $this->recipe($a, 'p-BOWL', ['RICE' => 1, 'BEEF' => 1]);
        app(ItemRoles::class)->setOwnerRole($this->organization->id, 'COMBO-BOX', ItemRole::Combo);

        $this->assertSame(4, $this->build());

        $combo = $this->profile('COMBO-BOX');
        $this->assertTrue($combo->is_existing_combo);
        $this->assertSame(['SANDWICH'], $combo->combo_members);
        $this->assertNull($this->profile('SANDWICH')->combo_members);
        $this->assertSame('meat', $this->profile('BOWL')->main_protein);

        $this->assertSame([
            'COMBO-BOX' => ['BREAD', 'CHICKEN', 'RICE'],
            'SANDWICH' => ['BREAD', 'CHICKEN'],
            'SODA' => [],
        ], app(ItemProfileBuilder::class)->foodComponentKeys($this->organization->id, ['COMBO-BOX', 'SANDWICH', 'SODA']));
    }

    public function test_the_recipe_comes_from_the_branch_with_the_most_rows(): void
    {
        $this->seedComponents();
        $this->product($this->branchA, 'platter-a', 'Platter', 'PLATTER', null, 'manufactured', 300);
        $this->product($this->branchB, 'platter-b', 'Platter', 'PLATTER', null, 'manufactured', 300);
        $this->recipe($this->branchA, 'platter-a', ['RICE' => 1, 'CHICKEN' => 1], version: 9);
        $this->recipe($this->branchB, 'platter-b', ['RICE' => 2, 'BEEF' => 1, 'SALAD' => 1]);

        // Same row count on both branches: the higher source_version wins.
        $this->product($this->branchA, 'stew-a', 'Stew', 'STEW', null, 'manufactured', 90);
        $this->product($this->branchB, 'stew-b', 'Stew', 'STEW', null, 'manufactured', 90);
        $this->recipe($this->branchA, 'stew-a', ['CHICKEN' => 1], version: 3);
        $this->recipe($this->branchB, 'stew-b', ['BEEF' => 1], version: 5);

        $this->assertSame(2, $this->build());

        $platter = $this->profile('PLATTER');
        $this->assertEquals(['rice' => 2.0, 'meat' => 1.0, 'salad' => 1.0], $platter->contains);
        $this->assertSame('meat', $platter->main_protein);
        $this->assertSame('meat', $this->profile('STEW')->main_protein);
    }

    public function test_an_org_without_items_writes_nothing_and_keeps_owner_profiles(): void
    {
        $log = Log::spy();
        ItemProfile::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'item_key' => 'KEEP',
            'name' => 'Keep',
            'serves_n' => 4,
            'serves_source' => 'owner',
            'price_tier' => 'family',
            'price_tier_source' => 'owner',
            'season_source' => 'auto',
            'list_price' => 100,
            'unit_cost' => 40,
            'contains' => [],
            'has_recipe' => false,
            'is_existing_combo' => false,
        ]);

        $this->assertSame(0, $this->build());

        $this->assertSame(4, $this->profile('KEEP')->serves_n);
        $log->shouldHaveReceived('warning')->once();
    }
}
