<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Domain\Menu\Intelligence\FoodClass;
use App\Domain\Menu\Intelligence\IntelligenceWriter;
use App\Filament\App\Pages\Management\ComboCandidatesPage;
use App\Filament\App\Pages\Management\ComponentClassesPage;
use App\Filament\App\Pages\Management\ItemCardsPage;
use App\Jobs\BuildMenuIntelligence;
use App\Models\Menu\ComboCandidate;
use App\Models\Menu\ComponentClass;
use App\Models\Menu\ItemProfile;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Item cards: KOFTA (single, auto tier), TRAY (pinned family tier by the owner), COLA (drink).
 * Components: RICE (owner), SALT (auto 0.40, untrusted), CUP (rule 0.90).
 * Combos: signature SIG-A in 2026-09, 2026-10 and rolling_90d; SIG-B in 2026-10 only.
 */
class ReviewPagesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-08 10:00:00');
        (new RolesAndPermissionsSeeder)->run();

        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->org = Organization::factory()->create(['plan' => 'pro', 'ai_enabled' => true]);
        $this->other = Organization::factory()->create(['plan' => 'pro', 'ai_enabled' => true]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->owner->assignRole('org_owner');

        IntelligenceWriter::replaceProfiles($this->org->id, [
            $this->profile('KOFTA', 'Kofta Plate', 'main', 1, 'single'),
            $this->profile('TRAY', 'Kings Tray', 'main', 4, 'family'),
            $this->profile('COLA', 'Cola Can', 'drink', 1, 'single'),
        ]);
        ItemProfile::query()->where('organization_id', $this->org->id)->where('item_key', 'TRAY')->update(['price_tier_source' => 'owner']);
        IntelligenceWriter::replaceProfiles($this->other->id, [$this->profile('OUTSIDER', 'Outsider Dish', 'main', 1, 'single')]);

        $this->componentClass($this->org, 'RICE', 'Rice Raw', 'rice', 'owner', 1.00);
        $this->componentClass($this->org, 'SALT', 'Salt Raw', 'other', 'auto', 0.40);
        $this->componentClass($this->org, 'CUP', 'Cup Raw', 'packaging', 'rule', 0.90);
        $this->componentClass($this->other, 'FOREIGN', 'Foreign Raw', 'meat', 'auto', 0.95);

        $this->combo($this->org, '2026-09', 'SIG-A', 'Kings Tray');
        $this->combo($this->org, '2026-10', 'SIG-A', 'Kings Tray');
        $this->combo($this->org, 'rolling_90d', 'SIG-A', 'Kings Tray');
        $this->combo($this->org, '2026-10', 'SIG-B', 'Kofta Plate', observed: false);
        $this->combo($this->other, '2026-10', 'SIG-A', 'Outsider Dish');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function profile(string $key, string $name, string $role, int $servesN, string $tier): array
    {
        return [
            'item_key' => $key,
            'name' => $name,
            'category_key' => 'mains',
            'role' => $role,
            'serves_n' => $servesN,
            'serves_source' => 'recipe',
            'price_tier' => $tier,
            'price_tier_source' => 'auto',
            'season' => 'all',
            'season_source' => 'auto',
            'list_price' => 100,
            'unit_cost' => 40,
            'contains' => $role === 'drink' ? [] : ['rice' => 1, 'salad' => 0.27],
            'main_protein' => null,
            'has_recipe' => $role !== 'drink',
            'is_existing_combo' => false,
            'combo_members' => null,
        ];
    }

    private function componentClass(Organization $org, string $key, string $name, string $class, string $source, float $confidence): void
    {
        ComponentClass::query()->create([
            'organization_id' => $org->id,
            'component_key' => $key,
            'name' => $name,
            'class' => $class,
            'source' => $source,
            'confidence' => $confidence,
            'classified_at' => now(),
        ]);
    }

    private function combo(Organization $org, string $period, string $signature, string $heroName, bool $observed = true): ComboCandidate
    {
        return ComboCandidate::query()->create([
            'organization_id' => $org->id,
            'period_key' => $period,
            'template' => 'family_feast',
            'channel' => 'all',
            'hero_key' => 'HERO-'.$signature,
            'signature' => sha1($signature),
            'items' => [
                ['key' => 'HERO-'.$signature, 'name' => $heroName, 'role' => 'main', 'qty' => 1, 'list_price' => 400.0],
                ['key' => 'WATER', 'name' => 'Water', 'role' => 'drink', 'qty' => 4, 'list_price' => 10.0],
                ['key' => 'SOUP', 'name' => 'Soup', 'role' => 'side', 'qty' => 1, 'list_price' => 50.0],
            ],
            'list_total' => 490,
            'cost_total' => 200,
            'suggested_price' => 450,
            'margin_pct' => 0.5556,
            'discount_pct' => 0.0816,
            'evidence' => $observed
                ? ['type' => 'observed', 'itemset' => [], 'together_orders' => 37, 'support' => 0.01, 'lift' => 2.5, 'why' => 'x']
                : ['type' => 'rule', 'why' => 'y'],
            'checks' => [],
            'overlaps_combo_key' => null,
            'score' => 0.7,
            'build_id' => '01JREVIEW0000000000000001',
        ]);
    }

    /** @return array<string, array{class-string}> */
    public static function pages(): array
    {
        return [
            'item cards' => [ItemCardsPage::class],
            'component classes' => [ComponentClassesPage::class],
            'combo candidates' => [ComboCandidatesPage::class],
        ];
    }

    /** @param  class-string  $page */
    #[DataProvider('pages')]
    public function test_page_renders_for_an_owner(string $page): void
    {
        $this->actingAs($this->owner);

        Livewire::test($page)->assertSuccessful();
    }

    /** @param  class-string  $page */
    #[DataProvider('pages')]
    public function test_page_is_forbidden_without_ai_manage(string $page): void
    {
        $analyst = User::factory()->create(['organization_id' => $this->org->id]);
        $analyst->assignRole('org_analyst');
        $this->actingAs($analyst);

        $this->assertFalse($page::canAccess());

        Livewire::test($page)->assertForbidden();
    }

    public function test_item_cards_list_only_the_organizations_profiles_with_a_contains_summary(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(ItemCardsPage::class)
            ->assertSee('بطاقات الأصناف')
            ->assertSee('Kofta Plate')
            ->assertSee('رز ×1، سلطة ×0.27')
            ->assertDontSee('Outsider Dish');

        $this->assertEqualsCanonicalizing(['KOFTA', 'TRAY', 'COLA'], $this->recordKeys($component));
    }

    public function test_item_cards_filter_by_price_tier_and_role_and_search_by_name(): void
    {
        $this->actingAs($this->owner);

        $this->assertSame(['TRAY'], $this->recordKeys(Livewire::test(ItemCardsPage::class)->filterTable('price_tier', 'family')));
        $this->assertSame(['COLA'], $this->recordKeys(Livewire::test(ItemCardsPage::class)->filterTable('role', 'drink')));
        $this->assertSame(['KOFTA'], $this->recordKeys(Livewire::test(ItemCardsPage::class)->searchTable('kofta')));
    }

    public function test_editing_serves_sets_the_owner_source_recomputes_an_auto_tier_and_survives_a_rebuild(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ItemCardsPage::class)
            ->call('updateTableColumnState', 'serves_n', 'KOFTA', '4')
            ->call('updateTableColumnState', 'serves_n', 'TRAY', '2');

        $kofta = $this->storedProfile('KOFTA');
        $this->assertSame([4, 'owner', 'family', 'auto'], [$kofta->serves_n, $kofta->serves_source, $kofta->price_tier, $kofta->price_tier_source]);

        // The owner pinned TRAY's tier, so changing who it serves leaves the tier alone.
        $tray = $this->storedProfile('TRAY');
        $this->assertSame([2, 'owner', 'family', 'owner'], [$tray->serves_n, $tray->serves_source, $tray->price_tier, $tray->price_tier_source]);

        IntelligenceWriter::replaceProfiles($this->org->id, [
            $this->profile('KOFTA', 'Kofta Plate', 'main', 1, 'family'),
            $this->profile('TRAY', 'Kings Tray', 'main', 4, 'family'),
            $this->profile('COLA', 'Cola Can', 'drink', 1, 'single'),
        ]);

        $kofta = $this->storedProfile('KOFTA');
        $this->assertSame([4, 'owner'], [$kofta->serves_n, $kofta->serves_source]);
    }

    public function test_editing_price_tier_and_season_writes_owner_sources(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ItemCardsPage::class)
            ->call('updateTableColumnState', 'price_tier', 'KOFTA', 'sharing')
            ->call('updateTableColumnState', 'season', 'KOFTA', 'winter')
            ->call('updateTableColumnState', 'season', 'COLA', 'not-a-season')
            ->call('updateTableColumnState', 'serves_n', 'COLA', '99');

        $kofta = $this->storedProfile('KOFTA');
        $this->assertSame(['sharing', 'owner', 'winter', 'owner'], [$kofta->price_tier, $kofta->price_tier_source, $kofta->season, $kofta->season_source]);

        $cola = $this->storedProfile('COLA');
        $this->assertSame(['all', 'auto', 1, 'recipe'], [$cola->season, $cola->season_source, $cola->serves_n, $cola->serves_source]);
    }

    public function test_editing_another_organizations_item_card_is_ignored(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ItemCardsPage::class)
            ->call('updateTableColumnState', 'serves_n', 'OUTSIDER', '6');

        $outsider = ItemProfile::query()->withoutGlobalScopes()->where('organization_id', $this->other->id)->where('item_key', 'OUTSIDER')->firstOrFail();
        $this->assertSame([1, 'recipe'], [$outsider->serves_n, $outsider->serves_source]);
    }

    public function test_rebuild_action_dispatches_the_build_for_the_organization(): void
    {
        Queue::fake();
        $this->actingAs($this->owner);

        Livewire::test(ItemCardsPage::class)
            ->callAction('rebuild')
            ->assertNotified('تم جدولة إعادة البناء');

        Queue::assertPushed(
            BuildMenuIntelligence::class,
            fn (BuildMenuIntelligence $job): bool => $job->organizationId === $this->org->id && $job->periodKeys === null,
        );
    }

    public function test_component_classes_list_untrusted_first_and_hide_other_organizations(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(ComponentClassesPage::class)
            ->assertSee('تصنيف الخامات')
            ->assertSee('قاعدة الاسم')
            ->assertDontSee('Foreign Raw');

        $this->assertSame(['SALT', 'CUP', 'RICE'], $this->recordKeys($component));
    }

    public function test_component_classes_filter_by_class_and_untrusted(): void
    {
        $this->actingAs($this->owner);

        $this->assertSame(['CUP'], $this->recordKeys(Livewire::test(ComponentClassesPage::class)->filterTable('class', 'packaging')));
        $this->assertSame(['SALT'], $this->recordKeys(Livewire::test(ComponentClassesPage::class)->filterTable('untrusted')));
    }

    public function test_editing_a_component_class_writes_an_owner_label(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ComponentClassesPage::class)
            ->call('updateTableColumnState', 'class', 'SALT', FoodClass::Sauce->value)
            ->call('updateTableColumnState', 'class', 'FOREIGN', FoodClass::Rice->value);

        $this->assertDatabaseHas('component_classes', [
            'organization_id' => $this->org->id,
            'component_key' => 'SALT',
            'class' => 'sauce',
            'source' => 'owner',
            'confidence' => 1.00,
        ]);
        $this->assertDatabaseHas('component_classes', ['organization_id' => $this->other->id, 'component_key' => 'FOREIGN', 'class' => 'meat', 'source' => 'auto']);
        $this->assertDatabaseMissing('component_classes', ['organization_id' => $this->org->id, 'component_key' => 'FOREIGN']);
    }

    public function test_combo_candidates_default_to_the_latest_complete_month_and_hide_other_organizations(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(ComboCandidatesPage::class)
            ->assertSee('Kings Tray + 4× Water + Soup')
            ->assertSee('اتطلبت مع بعض في 37 طلب')
            ->assertSee('مبنية على قواعد القالب')
            ->assertDontSee('Outsider Dish');

        $ids = $this->recordKeys($component);
        $this->assertCount(2, $ids);
        $this->assertSame(['2026-10'], ComboCandidate::query()->whereIn('id', $ids)->distinct()->pluck('period_key')->all());
    }

    public function test_approve_and_reject_update_every_period_row_with_the_same_signature(): void
    {
        $this->actingAs($this->owner);

        $october = ComboCandidate::query()->where('organization_id', $this->org->id)->where('period_key', '2026-10')->where('signature', sha1('SIG-A'))->firstOrFail();

        Livewire::test(ComboCandidatesPage::class)
            ->callAction(TestAction::make('approve')->table((string) $october->id), data: ['owner_note' => 'نجربه في الشتا'])
            ->assertNotified('تم حفظ القرار');

        $rows = ComboCandidate::query()->withoutGlobalScopes()->where('signature', sha1('SIG-A'))->get();
        $mine = $rows->where('organization_id', $this->org->id);
        $this->assertCount(3, $mine);
        $this->assertSame(['approved'], $mine->pluck('status')->unique()->values()->all());
        $this->assertSame(['نجربه في الشتا'], $mine->pluck('owner_note')->unique()->values()->all());
        $this->assertSame('candidate', $rows->firstWhere('organization_id', $this->other->id)?->status, 'another organization keeps its own decision');
        $this->assertSame('candidate', ComboCandidate::query()->where('signature', sha1('SIG-B'))->value('status'));

        Livewire::test(ComboCandidatesPage::class)
            ->callAction(TestAction::make('reject')->table((string) $october->id), data: ['owner_note' => '']);

        $mine = ComboCandidate::query()->where('organization_id', $this->org->id)->where('signature', sha1('SIG-A'))->get();
        $this->assertSame(['rejected'], $mine->pluck('status')->unique()->values()->all());
        $this->assertSame([null], $mine->pluck('owner_note')->unique()->values()->all());

        Livewire::test(ComboCandidatesPage::class)
            ->callAction(TestAction::make('reset')->table((string) $october->id));

        $this->assertSame(['candidate'], ComboCandidate::query()->where('organization_id', $this->org->id)->where('signature', sha1('SIG-A'))->distinct()->pluck('status')->all());
    }

    /**
     * Keys of the records the page's table shows, in order.
     *
     * @template TComponent of Component
     *
     * @param  Testable<TComponent>  $component
     * @return list<string>
     */
    private function recordKeys(Testable $component): array
    {
        $page = $component->instance();
        $this->assertInstanceOf(HasTable::class, $page);
        $records = $page->getTableRecords();
        $this->assertInstanceOf(LengthAwarePaginator::class, $records);

        return array_map('strval', array_keys($records->items()));
    }

    private function storedProfile(string $key): ItemProfile
    {
        return ItemProfile::query()->withoutGlobalScopes()->where('organization_id', $this->org->id)->where('item_key', $key)->firstOrFail();
    }
}
