<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Ai\Agents\ComponentClassifier;
use App\Domain\Menu\Intelligence\FoodClass;
use App\Domain\Menu\Intelligence\IntelligenceClassifier;
use App\Domain\Menu\Intelligence\MealType;
use App\Domain\Menu\Intelligence\Season;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Menu\CategoryProfile;
use App\Models\Menu\ComponentClass;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

class IntelligenceClassifierTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    /** @var list<string> prompts the fake model received, in order */
    private array $prompts = [];

    /** Component classes the fake model returns, keyed by component key. */
    private const COMPONENT_ANSWERS = [
        'RICE-MANDI' => ['rice', 0.90],
        'CHICKEN' => ['chicken', 0.85],
    ];

    /** [season, meal_type, confidence] the fake model returns, keyed by category key. */
    private const CATEGORY_ANSWERS = [
        'وجبات' => ['all', 'single', 0.80],
        'ramadan offers' => ['ramadan', 'family', 0.75],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Org Intelligence',
            'slug' => 'org-intel-'.Str::random(6),
            'plan' => 'pro',
            'ai_enabled' => true,
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);
        $restaurant = Restaurant::create(['organization_id' => $this->organization->id, 'name' => 'R']);
        $branches = [];
        foreach (['A', 'B'] as $letter) {
            $branches[$letter] = Branch::create([
                'organization_id' => $this->organization->id,
                'restaurant_id' => $restaurant->id,
                'name' => 'Branch '.$letter,
                'timezone' => 'Africa/Cairo',
                'opened_on' => '2026-08-01',
                'status' => BranchStatus::ACTIVE,
            ])->id;
        }

        $orgId = $this->organization->id;
        $a = $branches['A'];
        $b = $branches['B'];
        $dim = fn (int $branch, string $uuid, array $extra): array => [
            'organization_id' => $orgId,
            'branch_id' => $branch,
            'uuid' => $uuid,
            'source_version' => 1,
        ] + $extra;

        DB::table('dim_categories')->insert([
            $dim($a, 'cat-meals', ['name' => 'وجبات']),
            $dim($a, 'cat-ramadan', ['name' => '  Ramadan   Offers ']),
            $dim($b, 'cat-ramadan-b', ['name' => 'Ramadan Offers']),
        ]);

        $product = fn (int $branch, string $uuid, string $name, ?string $ref, ?string $category, string $type, float $price): array => $dim($branch, $uuid, [
            'name' => $name,
            'product_ref' => $ref,
            'category_uuid' => $category,
            'type' => $type,
            'price' => $price,
        ]);
        DB::table('dim_products')->insert([
            // Components. The fork has no product_ref, so its key is its uuid.
            $product($a, 'comp-fork', 'شوكه', null, null, 'consumable', 0),
            $product($a, 'comp-bag', 'كيس وسط لوجو', 'BAG-M', null, 'consumable', 0),
            $product($a, 'comp-rice', 'ارز مندي', 'RICE-MANDI', null, 'raw_material', 0),
            $product($a, 'comp-chicken', 'فراخ', 'CHICKEN', null, 'raw_material', 0),
            $product($b, 'comp-rice-b', 'ارز مندي', 'RICE-MANDI', null, 'raw_material', 0),
            // Menu products.
            $product($a, 'menu-mandi', 'وجبة مندي فراخ', 'MANDI-MEAL', 'cat-meals', 'manufactured', 180),
            $product($a, 'menu-family', 'صينية رمضان عائلي', 'FAMILY-TRAY', 'cat-ramadan', 'manufactured', 650),
            $product($b, 'menu-family-b', 'صينية رمضان عائلي', 'FAMILY-TRAY', 'cat-ramadan-b', 'manufactured', 700),
        ]);

        $recipe = fn (int $branch, string $productUuid, string $componentUuid): array => [
            'organization_id' => $orgId,
            'branch_id' => $branch,
            'product_uuid' => $productUuid,
            'component_uuid' => $componentUuid,
            'quantity' => 1,
            'source_version' => 1,
        ];
        DB::table('dim_recipes')->insert([
            $recipe($a, 'menu-mandi', 'comp-fork'),
            $recipe($a, 'menu-mandi', 'comp-bag'),
            $recipe($a, 'menu-mandi', 'comp-rice'),
            $recipe($a, 'menu-mandi', 'comp-chicken'),
            $recipe($a, 'menu-family', 'comp-rice'),
            $recipe($a, 'menu-family', 'comp-bag'),
            $recipe($b, 'menu-family-b', 'comp-rice-b'),
        ]);
    }

    /**
     * Fake the agent, answering components and categories from the given maps; unknown keys get a
     * low-confidence `other` / `all, single`.
     *
     * @param  array<string, array{0: string, 1: float}>  $components
     * @param  array<string, array{0: string, 1: string, 2: float}>  $categories
     */
    private function fakeModel(array $components = self::COMPONENT_ANSWERS, array $categories = self::CATEGORY_ANSWERS): void
    {
        ComponentClassifier::fake(function (string $prompt) use ($components, $categories): array {
            $this->prompts[] = $prompt;
            $entries = $this->entriesOf($prompt);

            if (str_contains($prompt, 'recipe components')) {
                return ['items' => array_map(fn (array $e): array => [
                    'key' => $e['key'],
                    'class' => $components[$e['key']][0] ?? 'other',
                    'confidence' => $components[$e['key']][1] ?? 0.40,
                ], $entries)];
            }

            return ['items' => array_map(fn (array $e): array => [
                'key' => $e['key'],
                'season' => $categories[$e['key']][0] ?? 'all',
                'meal_type' => $categories[$e['key']][1] ?? 'single',
                'confidence' => $categories[$e['key']][2] ?? 0.40,
            ], $entries)];
        });
    }

    /** @return list<array<string, mixed>> */
    private function entriesOf(string $prompt): array
    {
        return json_decode(substr($prompt, (int) strpos($prompt, '[')), true);
    }

    private function classifier(): IntelligenceClassifier
    {
        return app(IntelligenceClassifier::class);
    }

    private function componentRow(string $key): ComponentClass
    {
        return ComponentClass::query()->where('organization_id', $this->organization->id)->where('component_key', $key)->firstOrFail();
    }

    private function categoryRow(string $key): CategoryProfile
    {
        return CategoryProfile::query()->where('organization_id', $this->organization->id)->where('category_key', $key)->firstOrFail();
    }

    public function test_rules_label_cutlery_and_packaging_without_a_model_call(): void
    {
        $classifier = $this->classifier();
        $orgId = $this->organization->id;
        $classifier->setComponentClass($orgId, 'RICE-MANDI', FoodClass::Rice);
        $classifier->setComponentClass($orgId, 'CHICKEN', FoodClass::Chicken);
        $classifier->setCategoryProfile($orgId, 'وجبات', Season::All, MealType::Single);
        $classifier->setCategoryProfile($orgId, 'ramadan offers', Season::Ramadan, MealType::Family);
        $this->fakeModel();

        $result = $classifier->classify($this->organization);

        ComponentClassifier::assertNeverPrompted();
        $this->assertSame(['components' => 2, 'categories' => 0, 'deferred' => 0], $result);

        $fork = $this->componentRow('comp-fork');
        $this->assertSame(['شوكه', 'cutlery', 'rule'], [$fork->name, $fork->class, $fork->source]);
        $this->assertSame(0.95, $fork->confidence);
        $bag = $this->componentRow('BAG-M');
        $this->assertSame(['packaging', 'rule'], [$bag->class, $bag->source]);
    }

    public function test_the_model_labels_the_rest_and_receives_only_the_non_rule_keys(): void
    {
        $this->fakeModel();

        $result = $this->classifier()->classify($this->organization);

        $this->assertSame(['components' => 4, 'categories' => 2, 'deferred' => 0], $result);
        ComponentClassifier::assertPromptedTimes(2);

        [$componentPrompt, $categoryPrompt] = $this->prompts;
        $this->assertStringContainsString('recipe components', $componentPrompt);
        $componentEntries = $this->entriesOf($componentPrompt);
        $categoryEntries = $this->entriesOf($categoryPrompt);

        // Rice is used by two menu products (merged across branches); the rule keys never reach the model.
        $this->assertSame([
            ['key' => 'CHICKEN', 'name' => 'فراخ', 'used_in' => 1, 'sample_products' => ['وجبة مندي فراخ']],
            ['key' => 'RICE-MANDI', 'name' => 'ارز مندي', 'used_in' => 2, 'sample_products' => ['صينية رمضان عائلي', 'وجبة مندي فراخ']],
        ], $componentEntries);

        // Categories merge across branches by normalized name; an item keeps its highest branch price.
        $this->assertEqualsCanonicalizing([
            ['key' => 'وجبات', 'name' => 'وجبات', 'sample_items' => [['name' => 'وجبة مندي فراخ', 'price' => 180]]],
            ['key' => 'ramadan offers', 'name' => '  Ramadan   Offers ', 'sample_items' => [['name' => 'صينية رمضان عائلي', 'price' => 700]]],
        ], $categoryEntries);

        $rice = $this->componentRow('RICE-MANDI');
        $this->assertSame(['rice', 'auto'], [$rice->class, $rice->source]);
        $this->assertSame(0.90, $rice->confidence);
        $this->assertTrue($rice->classified_at->greaterThan(now()->subMinute()));
        $this->assertSame('chicken', $this->componentRow('CHICKEN')->class);

        $ramadan = $this->categoryRow('ramadan offers');
        $this->assertSame(['ramadan', 'family', 'auto'], [$ramadan->season, $ramadan->meal_type, $ramadan->source]);
        $this->assertSame(0.75, $ramadan->confidence);

        // A second run finds everything trusted and recent: nothing is asked again.
        $again = $this->classifier()->classify($this->organization);
        ComponentClassifier::assertPromptedTimes(2);
        $this->assertSame(['components' => 0, 'categories' => 0, 'deferred' => 0], $again);
    }

    public function test_owner_rows_are_never_overwritten(): void
    {
        $orgId = $this->organization->id;
        $classifier = $this->classifier();
        // The rule would call the bag packaging; the owner says otherwise.
        $classifier->setComponentClass($orgId, 'BAG-M', FoodClass::Other);
        $classifier->setComponentClass($orgId, 'RICE-MANDI', FoodClass::Bread);
        $classifier->setCategoryProfile($orgId, '  Ramadan   Offers ', Season::Winter, MealType::Kids);
        $this->fakeModel();

        $classifier->classify($this->organization);

        $bag = $this->componentRow('BAG-M');
        $this->assertSame(['other', 'owner', 'كيس وسط لوجو'], [$bag->class, $bag->source, $bag->name]);
        $this->assertSame(1.0, $bag->confidence);
        $this->assertSame(['bread', 'owner'], [$this->componentRow('RICE-MANDI')->class, $this->componentRow('RICE-MANDI')->source]);
        $ramadan = $this->categoryRow('ramadan offers');
        $this->assertSame(['winter', 'kids', 'owner', '  Ramadan   Offers '], [$ramadan->season, $ramadan->meal_type, $ramadan->source, $ramadan->name]);

        ComponentClassifier::assertNotPrompted(fn (AgentPrompt $p): bool => str_contains($p->prompt, 'RICE-MANDI') || str_contains($p->prompt, 'ramadan offers'));
        ComponentClassifier::assertPrompted(fn (AgentPrompt $p): bool => str_contains($p->prompt, 'CHICKEN'));
    }

    public function test_an_owner_label_set_while_the_model_answers_is_kept(): void
    {
        $orgId = $this->organization->id;
        ComponentClassifier::fake(function (string $prompt) use ($orgId): array {
            if (str_contains($prompt, 'recipe components')) {
                app(IntelligenceClassifier::class)->setComponentClass($orgId, 'CHICKEN', FoodClass::Meat);
            }

            return ['items' => array_map(fn (array $e): array => str_contains($prompt, 'recipe components')
                ? ['key' => $e['key'], 'class' => 'rice', 'confidence' => 0.9]
                : ['key' => $e['key'], 'season' => 'all', 'meal_type' => 'single', 'confidence' => 0.9], $this->entriesOf($prompt))];
        });

        $this->classifier()->classify($this->organization);

        $this->assertSame(['meat', 'owner'], [$this->componentRow('CHICKEN')->class, $this->componentRow('CHICKEN')->source]);
        $this->assertSame('rice', $this->componentRow('RICE-MANDI')->class);
    }

    public function test_recently_judged_rows_are_skipped_whatever_their_confidence(): void
    {
        $orgId = $this->organization->id;
        $label = fn (string $key, float $confidence, int $daysAgo) => ComponentClass::query()->create([
            'organization_id' => $orgId,
            'component_key' => $key,
            'name' => $key,
            'class' => 'other',
            'source' => 'auto',
            'confidence' => $confidence,
            'classified_at' => now()->subDays($daysAgo),
        ]);
        $label('RICE-MANDI', 0.90, 5);   // recent and trusted: skipped
        $label('CHICKEN', 0.90, 40);     // trusted but older than rejudge_days: asked again
        CategoryProfile::query()->create([
            'organization_id' => $orgId,
            'category_key' => 'وجبات',
            'name' => 'وجبات',
            'season' => 'summer',
            'meal_type' => 'single',
            'source' => 'auto',
            'confidence' => 0.50,           // recent but untrusted: skipped too
            'classified_at' => now()->subDay(),
        ]);
        $this->fakeModel();

        $this->classifier()->classify($this->organization);

        $this->assertSame('other', $this->componentRow('RICE-MANDI')->class);
        $this->assertSame('chicken', $this->componentRow('CHICKEN')->class);
        $this->assertSame('summer', $this->categoryRow('وجبات')->season);
        ComponentClassifier::assertNotPrompted(fn (AgentPrompt $p): bool => str_contains($p->prompt, 'RICE-MANDI'));
        ComponentClassifier::assertNotPrompted(fn (AgentPrompt $p): bool => str_contains($p->prompt, 'وجبات'));
    }

    public function test_untrusted_rows_older_than_rejudge_days_are_asked_again(): void
    {
        CategoryProfile::query()->create([
            'organization_id' => $this->organization->id,
            'category_key' => 'وجبات',
            'name' => 'وجبات',
            'season' => 'summer',
            'meal_type' => 'single',
            'source' => 'auto',
            'confidence' => 0.50,
            'classified_at' => now()->subDays((int) config('menu.intelligence.rejudge_days') + 1),
        ]);
        $this->fakeModel();

        $this->classifier()->classify($this->organization);

        ComponentClassifier::assertPrompted(fn (AgentPrompt $p): bool => str_contains($p->prompt, 'وجبات'));
        $row = $this->categoryRow('وجبات');
        $this->assertSame(['all', 0.80], [$row->season, $row->confidence]);
    }

    public function test_the_model_call_cap_defers_the_remainder(): void
    {
        config(['menu.intelligence.classification_batch' => 1]);
        $this->fakeModel();

        $result = $this->classifier()->classify($this->organization, maxModelCalls: 1);

        // Rules still apply; one component entry is asked, the other and both categories wait.
        ComponentClassifier::assertPromptedTimes(1);
        $this->assertSame(['components' => 3, 'categories' => 0, 'deferred' => 3], $result);
        $this->assertSame(0, CategoryProfile::query()->count());
    }

    public function test_ai_switched_off_defers_every_model_entry_but_applies_rules(): void
    {
        $this->organization->forceFill(['ai_enabled' => false])->save();
        $this->fakeModel();

        $result = $this->classifier()->classify($this->organization->refresh());

        ComponentClassifier::assertNeverPrompted();
        $this->assertSame(['components' => 2, 'categories' => 0, 'deferred' => 4], $result);
    }

    public function test_invalid_values_from_the_model_are_ignored(): void
    {
        ComponentClassifier::fake(fn (string $prompt): array => str_contains($prompt, 'recipe components')
            ? ['items' => [
                ['key' => 'CHICKEN', 'class' => 'pizza', 'confidence' => 0.9],
                ['key' => 'RICE-MANDI', 'class' => 'rice', 'confidence' => 1.7],
                ['key' => 'NOT-ASKED', 'class' => 'meat', 'confidence' => 0.9],
            ]]
            : ['items' => [
                ['key' => 'وجبات', 'season' => 'spring', 'meal_type' => 'single', 'confidence' => 0.9],
                ['key' => 'ramadan offers', 'season' => 'ramadan', 'meal_type' => 'banquet', 'confidence' => 0.9],
            ]]);

        $result = $this->classifier()->classify($this->organization);

        $this->assertSame(['components' => 3, 'categories' => 0, 'deferred' => 0], $result);
        $this->assertSame(
            ['BAG-M', 'RICE-MANDI', 'comp-fork'],
            ComponentClass::query()->pluck('component_key')->sort()->values()->all(),
        );
        // An out-of-range confidence is clamped.
        $this->assertSame(1.0, $this->componentRow('RICE-MANDI')->confidence);
        $this->assertSame(0, CategoryProfile::query()->count());
    }

    public function test_name_rules(): void
    {
        $this->assertSame(FoodClass::Cutlery, IntelligenceClassifier::ruleClass('معلقة بلاستيك'));
        $this->assertSame(FoodClass::Cutlery, IntelligenceClassifier::ruleClass('Plastic Spoons'));
        $this->assertSame(FoodClass::Packaging, IntelligenceClassifier::ruleClass('ورق زبدة'));
        $this->assertSame(FoodClass::Packaging, IntelligenceClassifier::ruleClass('Paper bag large'));
        $this->assertNull(IntelligenceClassifier::ruleClass('Baguette'));
        $this->assertNull(IntelligenceClassifier::ruleClass('ارز مندي'));
    }
}
