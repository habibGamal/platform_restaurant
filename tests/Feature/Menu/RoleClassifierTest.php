<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Ai\Agents\ItemRoleClassifier;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Domain\Menu\RoleClassifier;
use App\Jobs\ClassifyItemRoles;
use App\Models\AiSetting;
use App\Models\CategoryRoleAssignment;
use App\Models\ItemRoleAssignment;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     */
    private function fakeModel(array $itemAnswers = []): void
    {
        ItemRoleClassifier::fake(function (string $prompt) use ($itemAnswers): array {
            $entries = json_decode(substr($prompt, (int) strpos($prompt, '[')), true);
            $answers = str_contains($prompt, 'categories') ? self::CATEGORY_ANSWERS : $itemAnswers;

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

        $this->assertSame(['classified' => 0, 'skipped' => 2], $result);
        ItemRoleClassifier::assertNeverPrompted();
        $this->assertSame(0, ItemRoleAssignment::query()->count());
    }

    public function test_skips_org_with_exhausted_budget(): void
    {
        $f = MenuFixture::create();
        AiSetting::factory()->create([
            'organization_id' => $f->organization->id,
            'monthly_budget_egp' => 0,
            'hard_stop' => true,
        ]);
        $this->fakeModel();

        $result = $this->classifier()->classify($f->organization);

        $this->assertSame(0, $result['classified']);
        ItemRoleClassifier::assertNeverPrompted();
        $this->assertSame(0, ItemRoleAssignment::query()->count());
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

    public function test_job_is_unique_per_organization_and_delegates_to_the_classifier(): void
    {
        $f = MenuFixture::create();
        $this->fakeModel();

        $job = new ClassifyItemRoles($f->organization->id);
        $this->assertSame((string) $f->organization->id, $job->uniqueId());

        $job->handle($this->classifier());

        $this->assertDatabaseHas('item_roles', ['organization_id' => $f->organization->id, 'item_key' => 'ZORBA-PLATE']);
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
