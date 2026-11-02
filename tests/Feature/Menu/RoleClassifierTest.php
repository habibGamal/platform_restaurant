<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Ai\Agents\ItemRoleClassifier;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Domain\Menu\RoleClassifier;
use App\Jobs\ClassifyItemRoles;
use App\Models\AiModelPrice;
use App\Models\AiSetting;
use App\Models\AiUsageLedgerEntry;
use App\Models\CategoryRoleAssignment;
use App\Models\ItemRoleAssignment;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use RuntimeException;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

class RoleClassifierTest extends TestCase
{
    use RefreshDatabase;

    /** Category roles the fake model returns, keyed by normalized category name. */
    private const CATEGORY_ANSWERS = [
        'mains' => ['main', 0.95],
        'sides' => ['side', 0.90],
        'drinks' => ['drink', 0.90],
        'extras' => ['mixed', 0.60],
    ];

    /**
     * Fake the classifier: categories answered from CATEGORY_ANSWERS, items from $itemAnswers
     * (key => [role, confidence]); unknown items get a low-confidence side.
     *
     * @param  array<string, array{0: string, 1: float}>  $itemAnswers
     * @param  array<string, array{0: string, 1: float}>  $categoryAnswers
     */
    private function fakeModel(array $itemAnswers = [], array $categoryAnswers = self::CATEGORY_ANSWERS): void
    {
        ItemRoleClassifier::fake(function (string $prompt) use ($itemAnswers, $categoryAnswers): array {
            $entries = json_decode(substr($prompt, (int) strpos($prompt, '[')), true);
            $answers = str_contains($prompt, 'categories') ? $categoryAnswers : $itemAnswers;

            return ['items' => array_map(
                fn (array $entry): array => [
                    'key' => $entry['key'],
                    'role' => $answers[$entry['key']][0] ?? 'side',
                    'confidence' => $answers[$entry['key']][1] ?? 0.40,
                ],
                $entries,
            )];
        });
    }

    private function classifier(): RoleClassifier
    {
        return app(RoleClassifier::class);
    }

    public function test_categories_are_classified_once_then_items_inherit(): void
    {
        $f = MenuFixture::create();
        $this->fakeModel(['GLAZE-DIP' => ['addon', 0.80]]);

        $result = $this->classifier()->classify($f->organization);

        // One call for the four categories that hold judgeable items, one for the item in the mixed category.
        ItemRoleClassifier::assertPromptedTimes(2);
        ItemRoleClassifier::assertPrompted(fn ($p) => str_contains($p->prompt, 'categories'));

        $orgId = $f->organization->id;
        $row = fn (string $key) => ItemRoleAssignment::query()
            ->where('organization_id', $orgId)->where('item_key', $key)->firstOrFail();

        foreach (['ZORBA-PLATE', 'KELTO-WRAP', 'BRIMO-BOX', 'NADD-BOWL', 'FETA-TOWER', 'QUILL-STEW'] as $key) {
            $this->assertSame('main', $row($key)->role, $key);
            $this->assertSame('auto', $row($key)->source, $key);
            $this->assertSame('0.95', $row($key)->confidence, $key);
        }
        $this->assertSame('side', $row('PEXA-SALAD')->role);
        $this->assertSame('drink', $row('TINY-SPRING')->role);

        // The mixed category's item was judged on its own.
        $this->assertSame('addon', $row('GLAZE-DIP')->role);
        $this->assertSame('auto', $row('GLAZE-DIP')->source);
        $this->assertSame('0.80', $row('GLAZE-DIP')->confidence);

        // Data-only roles are written without the model.
        $this->assertSame(['adjustment', 'signal', '1.00'], [$row('DIFF-LINE')->role, $row('DIFF-LINE')->source, $row('DIFF-LINE')->confidence]);
        $this->assertSame(['raw', 'signal', '1.00'], [$row('BULK-FLOUR')->role, $row('BULK-FLOUR')->source, $row('BULK-FLOUR')->confidence]);

        $this->assertSame(13, $result['classified']);

        // Categories are remembered, mixed ones as a null role.
        $this->assertSame(4, CategoryRoleAssignment::query()->where('organization_id', $orgId)->count());
        $this->assertNull(CategoryRoleAssignment::query()->where('category_key', 'extras')->firstOrFail()->role);

        // A second run has nothing left to ask the model.
        $this->classifier()->classify($f->organization);
        ItemRoleClassifier::assertPromptedTimes(2);
    }

    public function test_owner_rows_are_never_overwritten(): void
    {
        $f = MenuFixture::create();
        $orgId = $f->organization->id;
        app(ItemRoles::class)->setOwnerRole($orgId, 'ZORBA-PLATE', ItemRole::Combo);
        app(ItemRoles::class)->setOwnerRole($orgId, 'DIFF-LINE', ItemRole::Main);
        app(ItemRoles::class)->setOwnerRole($orgId, 'GLAZE-DIP', ItemRole::Drink);
        $this->fakeModel();

        $this->classifier()->classify($f->organization);

        foreach (['ZORBA-PLATE' => 'combo', 'DIFF-LINE' => 'main', 'GLAZE-DIP' => 'drink'] as $key => $role) {
            $row = ItemRoleAssignment::query()->where('organization_id', $orgId)->where('item_key', $key)->firstOrFail();
            $this->assertSame('owner', $row->source, $key);
            $this->assertSame($role, $row->role, $key);
        }

        // Items the owner already settled are not sent to the model.
        ItemRoleClassifier::assertNotPrompted(fn ($p) => str_contains($p->prompt, 'ZORBA-PLATE') && ! str_contains($p->prompt, 'categories'));
        ItemRoleClassifier::assertNotPrompted(fn ($p) => str_contains($p->prompt, 'GLAZE-DIP'));
    }

    public function test_keys_the_model_was_not_asked_about_are_ignored(): void
    {
        $f = MenuFixture::create();
        ItemRoleClassifier::fake(fn (string $prompt): array => ['items' => [
            ['key' => 'SOMETHING-ELSE', 'role' => 'main', 'confidence' => 0.99],
            ['key' => 'mains', 'role' => 'not-a-role', 'confidence' => 0.99],
        ]]);

        $this->classifier()->classify($f->organization);

        $this->assertDatabaseMissing('item_roles', ['item_key' => 'SOMETHING-ELSE']);
        $this->assertSame(0, CategoryRoleAssignment::query()->count());
        // Only the data-only roles exist.
        $this->assertSame(2, ItemRoleAssignment::query()->where('organization_id', $f->organization->id)->count());
    }

    public function test_usage_is_recorded_as_system_entry_without_user(): void
    {
        $f = MenuFixture::create();
        ItemRoleClassifier::fake(fn (string $prompt): StructuredTextResponse => new StructuredTextResponse(
            ['items' => []],
            '{"items":[]}',
            new TextUsage(inputTokens: 1200, outputTokens: 80),
            new Meta('openai', 'gpt-test'),
        ));

        $this->classifier()->classify($f->organization);

        $this->assertDatabaseHas('ai_usage_ledger', [
            'organization_id' => $f->organization->id,
            'user_id' => null,
            'channel' => 'system',
            'provider' => 'openai',
            'model' => 'gpt-test',
            'input_tokens' => 1200,
            'output_tokens' => 80,
        ]);
    }

    public function test_skips_org_without_ai_access(): void
    {
        $f = MenuFixture::create();
        $f->organization->forceFill(['plan' => 'basic'])->save();
        $this->fakeModel();

        $result = $this->classifier()->classify($f->organization->refresh(), ['ZORBA-PLATE', 'KELTO-WRAP']);

        $this->assertSame(['classified' => 0, 'skipped' => 2, 'deferred' => 0], $result);
        ItemRoleClassifier::assertNeverPrompted();
        $this->assertSame(0, ItemRoleAssignment::query()->count());
    }

    public function test_exhausted_budget_still_applies_hard_roles_but_makes_no_model_call(): void
    {
        $f = MenuFixture::create();
        AiSetting::factory()->create([
            'organization_id' => $f->organization->id,
            'monthly_budget_egp' => 0,
            'hard_stop' => true,
        ]);
        $this->fakeModel();

        $result = $this->classifier()->classify($f->organization);

        ItemRoleClassifier::assertNeverPrompted();
        $this->assertSame(0, AiUsageLedgerEntry::query()->count());
        $this->assertSame(0, CategoryRoleAssignment::query()->count());
        $this->assertSame(2, $result['classified']);
        $this->assertSame(
            ['BULK-FLOUR' => 'signal', 'DIFF-LINE' => 'signal'],
            ItemRoleAssignment::query()->orderBy('item_key')->pluck('source', 'item_key')->all(),
        );
    }

    public function test_budget_running_out_mid_run_keeps_stored_rows_and_stops_asking(): void
    {
        $f = MenuFixture::create();
        AiSetting::factory()->create([
            'organization_id' => $f->organization->id,
            'monthly_budget_egp' => 100,
            'hard_stop' => true,
        ]);
        AiModelPrice::query()->create([
            'provider' => 'openai',
            'model' => 'gpt-test',
            'input_per_1m' => 100,
            'output_per_1m' => 100,
            'cache_read_per_1m' => 0,
            'cache_write_per_1m' => 0,
            'currency' => 'USD',
            'effective_from' => '2020-01-01',
        ]);
        // The category call answers for the four categories (extras mixed) and costs far more than the budget.
        ItemRoleClassifier::fake(function (string $prompt): StructuredTextResponse {
            $entries = json_decode(substr($prompt, (int) strpos($prompt, '[')), true);
            $items = array_map(fn (array $e): array => [
                'key' => $e['key'],
                'role' => ['mains' => 'main', 'sides' => 'side', 'drinks' => 'drink'][$e['key']] ?? 'mixed',
                'confidence' => 0.9,
            ], $entries);

            return new StructuredTextResponse(
                ['items' => $items],
                '{}',
                new TextUsage(inputTokens: 1_000_000, outputTokens: 0),
                new Meta('openai', 'gpt-test'),
            );
        });

        $this->classifier()->classify($f->organization);

        // Only the category call happened; the one item left in the mixed category was never asked about.
        ItemRoleClassifier::assertPromptedTimes(1);
        $this->assertSame(1, AiUsageLedgerEntry::query()->count());
        $this->assertSame(4, CategoryRoleAssignment::query()->count());
        $this->assertSame('main', ItemRoleAssignment::query()->where('item_key', 'ZORBA-PLATE')->value('role'));
        $this->assertDatabaseMissing('item_roles', ['item_key' => 'GLAZE-DIP']);
    }

    public function test_items_judged_within_the_reclassify_window_are_not_asked_again(): void
    {
        $f = MenuFixture::create();
        $orgId = $f->organization->id;
        $assign = fn (string $key, string $classifiedAt) => ItemRoleAssignment::query()->create([
            'organization_id' => $orgId,
            'item_key' => $key,
            'role' => 'side',
            'source' => 'auto',
            'confidence' => 0.40,
            'classified_at' => $classifiedAt,
        ]);
        $assign('ZORBA-PLATE', now()->subDays(2)->toDateTimeString());
        $assign('KELTO-WRAP', now()->subDays(10)->toDateTimeString());
        $this->fakeModel();

        $map = app(ItemRoles::class)->ensureClassified($f->organization, ['ZORBA-PLATE', 'KELTO-WRAP']);

        // Zorba stays untrusted and untouched; Kelto was old enough to be re-judged.
        $this->assertFalse($map->isTrusted('ZORBA-PLATE'));
        $this->assertSame(ItemRole::Main, $map->roleOf('KELTO-WRAP'));
        $zorba = ItemRoleAssignment::query()->where('item_key', 'ZORBA-PLATE')->firstOrFail();
        $this->assertSame(['side', '0.40'], [$zorba->role, $zorba->confidence]);

        // The full run honours the same window.
        $this->classifier()->classify($f->organization);
        $this->assertSame('0.40', ItemRoleAssignment::query()->where('item_key', 'ZORBA-PLATE')->value('confidence'));
    }

    public function test_a_category_below_the_trust_threshold_falls_through_to_per_item_classification(): void
    {
        $f = MenuFixture::create();
        $this->fakeModel(
            ['ZORBA-PLATE' => ['combo', 0.85], 'KELTO-WRAP' => ['main', 0.75]],
            ['mains' => ['main', 0.60], 'sides' => ['side', 0.90], 'drinks' => ['drink', 0.90], 'extras' => ['mixed', 0.60]],
        );

        $this->classifier()->classify($f->organization, ['ZORBA-PLATE', 'KELTO-WRAP']);

        $this->assertSame('0.60', CategoryRoleAssignment::query()->where('category_key', 'mains')->value('confidence'));
        ItemRoleClassifier::assertPromptedTimes(2);
        ItemRoleClassifier::assertPrompted(fn ($p) => str_contains($p->prompt, 'ZORBA-PLATE') && ! str_contains($p->prompt, 'categories'));
        $this->assertSame('combo', ItemRoleAssignment::query()->where('item_key', 'ZORBA-PLATE')->value('role'));
        $this->assertSame('0.85', ItemRoleAssignment::query()->where('item_key', 'ZORBA-PLATE')->value('confidence'));
    }

    public function test_an_owner_assignment_made_during_the_model_call_is_not_overwritten(): void
    {
        $f = MenuFixture::create();
        $orgId = $f->organization->id;
        ItemRoleClassifier::fake(function (string $prompt) use ($orgId): array {
            $entries = json_decode(substr($prompt, (int) strpos($prompt, '[')), true);
            $isCategories = str_contains($prompt, 'categories');
            if (! $isCategories) {
                // The owner decides while the model is still answering.
                app(ItemRoles::class)->setOwnerRole($orgId, 'GLAZE-DIP', ItemRole::Drink);
            }

            return ['items' => array_map(fn (array $e): array => [
                'key' => $e['key'],
                'role' => $isCategories ? 'mixed' : 'addon',
                'confidence' => 0.9,
            ], $entries)];
        });

        $this->classifier()->classify($f->organization);

        $row = ItemRoleAssignment::query()->where('item_key', 'GLAZE-DIP')->firstOrFail();
        $this->assertSame(['drink', 'owner'], [$row->role, $row->source]);
        $this->assertSame('addon', ItemRoleAssignment::query()->where('item_key', 'ZORBA-PLATE')->value('role'));
    }

    public function test_window_is_anchored_on_the_latest_business_date_not_today(): void
    {
        $f = MenuFixture::create();
        $this->travelTo(Carbon::parse('2027-06-01'));
        $this->fakeModel(['GLAZE-DIP' => ['addon', 0.80]]);

        $result = $this->classifier()->classify($f->organization);

        $this->assertSame(13, $result['classified']);
    }

    public function test_only_item_keys_limits_the_work(): void
    {
        $f = MenuFixture::create();
        $this->fakeModel();

        $result = $this->classifier()->classify($f->organization, ['ZORBA-PLATE', 'DIFF-LINE']);

        $this->assertSame(2, $result['classified']);
        $this->assertSame(
            ['DIFF-LINE', 'ZORBA-PLATE'],
            ItemRoleAssignment::query()->orderBy('item_key')->pluck('item_key')->all(),
        );
        // Only the category of the one judgeable item was asked about.
        $this->assertSame(['mains'], CategoryRoleAssignment::query()->pluck('category_key')->all());
    }

    public function test_ensure_classified_makes_at_most_one_call_and_survives_failure(): void
    {
        $f = MenuFixture::create();
        ItemRoleClassifier::fake(fn () => throw new RuntimeException('model unavailable'));

        $map = app(ItemRoles::class)->ensureClassified($f->organization, ['ZORBA-PLATE', 'PEXA-SALAD']);

        $this->assertSame(['ZORBA-PLATE', 'PEXA-SALAD'], $map->untrustedAmong(['ZORBA-PLATE', 'PEXA-SALAD']));
        ItemRoleClassifier::assertPromptedTimes(1);
    }

    public function test_ensure_classified_classifies_missing_keys_then_stops_asking(): void
    {
        $f = MenuFixture::create();
        $this->fakeModel();
        app(ItemRoles::class)->setOwnerRole($f->organization->id, 'PEXA-SALAD', ItemRole::Side);

        $map = app(ItemRoles::class)->ensureClassified($f->organization, ['ZORBA-PLATE', 'PEXA-SALAD']);

        $this->assertSame(ItemRole::Main, $map->roleOf('ZORBA-PLATE'));
        $this->assertSame(ItemRole::Side, $map->roleOf('PEXA-SALAD'));
        // Only the untrusted key was classified: one category call, and Zorba inherits the answer.
        ItemRoleClassifier::assertPromptedTimes(1);

        $before = ItemRoleAssignment::query()->count();
        app(ItemRoles::class)->ensureClassified($f->organization, ['ZORBA-PLATE', 'PEXA-SALAD']);
        ItemRoleClassifier::assertPromptedTimes(1);
        $this->assertSame($before, ItemRoleAssignment::query()->count());
    }

    public function test_on_demand_classification_makes_one_model_call_and_queues_the_rest(): void
    {
        Queue::fake();
        $f = MenuFixture::create();
        // The category is not confident enough to inherit, so the items would need a second call.
        $this->fakeModel(
            ['ZORBA-PLATE' => ['main', 0.90], 'KELTO-WRAP' => ['main', 0.90]],
            ['mains' => ['main', 0.60], 'sides' => ['side', 0.90], 'drinks' => ['drink', 0.90], 'extras' => ['mixed', 0.60]],
        );

        $map = app(ItemRoles::class)->ensureClassified($f->organization, ['ZORBA-PLATE', 'KELTO-WRAP']);

        ItemRoleClassifier::assertPromptedTimes(1);
        ItemRoleClassifier::assertPrompted(fn ($p) => str_contains($p->prompt, 'categories'));
        $this->assertSame(['ZORBA-PLATE', 'KELTO-WRAP'], $map->untrustedAmong(['ZORBA-PLATE', 'KELTO-WRAP']));
        Queue::assertPushed(ClassifyItemRoles::class, fn (ClassifyItemRoles $job): bool => $job->organizationId === $f->organization->id);
    }

    public function test_on_demand_classification_with_known_categories_makes_one_item_call(): void
    {
        Queue::fake();
        $f = MenuFixture::create();
        CategoryRoleAssignment::query()->create([
            'organization_id' => $f->organization->id,
            'category_key' => 'mains',
            'role' => 'main',
            'confidence' => 0.60,
            'source' => 'auto',
        ]);
        $this->fakeModel(['ZORBA-PLATE' => ['main', 0.90], 'KELTO-WRAP' => ['combo', 0.85]]);

        $map = app(ItemRoles::class)->ensureClassified($f->organization, ['ZORBA-PLATE', 'KELTO-WRAP']);

        ItemRoleClassifier::assertPromptedTimes(1);
        ItemRoleClassifier::assertNotPrompted(fn ($p) => str_contains($p->prompt, 'categories'));
        $this->assertSame(ItemRole::Main, $map->roleOf('ZORBA-PLATE'));
        $this->assertSame(ItemRole::Combo, $map->roleOf('KELTO-WRAP'));
        Queue::assertNothingPushed();
    }

    public function test_on_demand_classification_of_settled_items_computes_no_signals(): void
    {
        $f = MenuFixture::create();
        $orgId = $f->organization->id;
        app(ItemRoles::class)->setOwnerRole($orgId, 'ZORBA-PLATE', ItemRole::Main);
        ItemRoleAssignment::query()->create([
            'organization_id' => $orgId,
            'item_key' => 'KELTO-WRAP',
            'role' => 'side',
            'source' => 'auto',
            'confidence' => 0.40,
            'classified_at' => now()->subDay(),
        ]);
        $this->fakeModel();
        $signalQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$signalQueries): void {
            if (str_contains($query->sql, 'wh_order_items')) {
                $signalQueries++;
            }
        });

        $result = $this->classifier()->classify($f->organization, ['ZORBA-PLATE', 'KELTO-WRAP'], maxModelCalls: 1);

        $this->assertSame(['classified' => 0, 'skipped' => 2, 'deferred' => 0], $result);
        $this->assertSame(0, $signalQueries);
        ItemRoleClassifier::assertNeverPrompted();
    }

    public function test_job_is_unique_per_organization_and_delegates_to_the_classifier(): void
    {
        $f = MenuFixture::create();
        $this->fakeModel();

        $job = new ClassifyItemRoles($f->organization->id);
        $this->assertSame((string) $f->organization->id, $job->uniqueId());

        $job->handle($this->classifier());

        $this->assertDatabaseHas('item_roles', ['organization_id' => $f->organization->id, 'item_key' => 'ZORBA-PLATE']);
    }

    public function test_job_lock_expires_so_a_killed_worker_cannot_block_the_organization(): void
    {
        $job = new ClassifyItemRoles(1);

        $this->assertSame(900, $job->uniqueFor);
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }

    public function test_job_ignores_a_deleted_organization(): void
    {
        $this->fakeModel();

        (new ClassifyItemRoles(999999))->handle($this->classifier());

        ItemRoleClassifier::assertNeverPrompted();
    }

    public function test_daily_dispatch_covers_only_organizations_with_ai_access(): void
    {
        Queue::fake();
        $eligible = Organization::factory()->create(['plan' => 'pro', 'ai_enabled' => true]);
        Organization::factory()->create(['plan' => 'pro', 'ai_enabled' => false]);
        Organization::factory()->create(['plan' => 'basic', 'ai_enabled' => true]);

        ClassifyItemRoles::dispatchForEligibleOrganizations();

        Queue::assertPushed(ClassifyItemRoles::class, 1);
        Queue::assertPushed(ClassifyItemRoles::class, fn (ClassifyItemRoles $j) => $j->organizationId === $eligible->id);
    }

    public function test_daily_schedule_is_registered(): void
    {
        $events = collect(Schedule::events())->filter(fn ($e) => $e->description === 'classify-item-roles');

        $this->assertCount(1, $events);
        $this->assertSame('0 4 * * *', $events->first()->expression);
    }
}
