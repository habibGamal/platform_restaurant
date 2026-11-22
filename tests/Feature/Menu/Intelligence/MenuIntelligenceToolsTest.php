<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Ai\Tools\Menu\GetAffinities;
use App\Ai\Tools\Menu\GetComboCandidates;
use App\Ai\Tools\Menu\GetItemCard;
use App\Ai\Tools\Menu\RankItems;
use App\Domain\Assistant\Evals\EvalRunner;
use App\Domain\Assistant\GroundingResult;
use App\Enums\BranchStatus;
use App\Jobs\BuildMenuIntelligence;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Prepared rows for September and October 2026 (today 2026-11-08), organization-wide:
 *
 *   main:single  SHAWARMA rank 1, KOFTA rank 2      main:family  TRAY rank 1 («صينية فراخ عائلي»)
 *   side:single  RICE rank 1                        protect: KOFTA (main:single)
 *   October itemsets (none in September): SHAWARMA+RICE (lift 2.5, 40 orders), SHAWARMA+COLA, SHAWARMA+RICE+COLA, SHAWARMA+5001
 *   October combos for SHAWARMA: approved (score 0.5), candidate (0.9), rejected (0.99)
 *
 * A second organization holds its own rows under the same item keys.
 */
class MenuIntelligenceToolsTest extends TestCase
{
    use RefreshDatabase;

    private const BUILD = '01JTOOLS00000000000000001';

    private Organization $organization;

    private Branch $branchA;

    private Branch $branchB;

    private User $owner;

    private User $manager;

    private Organization $other;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        (new RolesAndPermissionsSeeder)->run();

        [$this->organization, [$this->branchA, $this->branchB], $this->owner] = $this->organizationWith(2);
        $this->manager = $this->user($this->organization, 'branch_manager');
        $this->manager->branches()->attach($this->branchA->id);
        [$this->other, , $this->otherOwner] = $this->organizationWith(1);

        $org = $this->organization->id;
        $this->profile($org, 'SHAWARMA', 'وجبة شاورما', 'main', 'single');
        $this->profile($org, 'KOFTA', 'سندوتش كفتة', 'main', 'single');
        $this->profile($org, 'TRAY', 'صينية فراخ عائلي', 'main', 'family', serves: 4, contains: ['rice' => 300, 'chicken' => 1000, 'other' => 2000]);
        $this->profile($org, 'RICE', 'أرز بسمتي', 'side', 'single');
        $this->profile($org, 'COLA', 'كولا', 'drink', 'single');
        $this->profile($org, '5001', 'أم علي', 'dessert', 'single');

        foreach (['2026-09', '2026-10'] as $period) {
            $this->stat($org, $period, 'SHAWARMA', 100, 8000, 3000);
            $this->stat($org, $period, 'KOFTA', 300, 15000, 9000);
            $this->stat($org, $period, 'TRAY', 20, 8000, 4000);
            $this->stat($org, $period, 'RICE', 50, 1000, 300);
            $this->stat($org, $period, 'COLA', 400, 4000, 1200);
            $this->ranking($org, $period, 'push_next_month', 'main:single', 'SHAWARMA', 1, 0.8);
            $this->ranking($org, $period, 'push_next_month', 'main:single', 'KOFTA', 2, 0.4);
            $this->ranking($org, $period, 'push_next_month', 'main:family', 'TRAY', 1, 0.6);
            $this->ranking($org, $period, 'push_next_month', 'side:single', 'RICE', 1, 0.5);
            $this->ranking($org, $period, 'protect', 'main:single', 'KOFTA', 1, 0.9);
        }

        $this->affinity($org, '2026-10', ['SHAWARMA', 'RICE'], 40, 2.5);
        $this->affinity($org, '2026-10', ['SHAWARMA', 'COLA'], 80, 1.2);
        $this->affinity($org, '2026-10', ['SHAWARMA', 'RICE', 'COLA'], 30, 1.8);
        $this->affinity($org, '2026-10', ['5001', 'SHAWARMA'], 12, 1.5);

        $this->combo($org, '2026-10', 'SHAWARMA', 'approved', 0.5, 'a');
        $this->combo($org, '2026-10', 'SHAWARMA', 'candidate', 0.9, 'b');
        $this->combo($org, '2026-10', 'SHAWARMA', 'rejected', 0.99, 'c');

        // The other organization: same keys, its own names and numbers.
        $this->profile($this->other->id, 'SHAWARMA', 'شاورما تانية', 'main', 'single');
        $this->stat($this->other->id, '2026-10', 'SHAWARMA', 7, 70, 20);
        $this->ranking($this->other->id, '2026-10', 'push_next_month', 'main:single', 'SHAWARMA', 1, 0.5);

        // Completion markers: a period is only ready once its build finished.
        $this->marker($org, '2026-09', 5);
        $this->marker($org, '2026-10', 5);
        $this->marker($this->other->id, '2026-10', 1);
    }

    public function test_rank_items_without_a_segment_returns_one_list_per_segment(): void
    {
        $result = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'months' => ['2026-10', '2026-09']]);

        $this->assertSame('ok', $result['metrics']['status']);
        $this->assertSame(['main:single', 'main:family', 'side:single'], array_keys($result['metrics']['segments']));
        $single = $result['metrics']['segments']['main:single'];
        $this->assertSame('طبق رئيسي - فردي', $single['label']);
        $this->assertSame(['SHAWARMA', 'KOFTA'], array_column($single['items'], 'item_key'));
        $this->assertSame('وجبة شاورما', $single['items'][0]['name']);
        // Both months summed: 200 sold, 16000 net, 6000 cogs.
        $this->assertEquals(200, $single['items'][0]['qty']);
        $this->assertEquals(16000, $single['items'][0]['net_sales']);
        $this->assertEquals(50, $single['items'][0]['unit_margin']);
        $this->assertEquals(0.625, $single['items'][0]['margin_pct']);
        $this->assertSame(['2026-09', '2026-10'], array_keys($result['metrics']['periods']));
        $this->assertSame(['from' => '2026-09-01', 'to' => '2026-09-30'], $result['metrics']['periods']['2026-09']);
        $this->assertNotNull($result['metrics']['prepared_at']);

        $family = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'months' => ['2026-10'], 'segment' => 'main:family']);
        $this->assertSame(['main:family'], array_keys($family['metrics']['segments']));
        $this->assertArrayHasKey('factors', $family['metrics']['segments']['main:family']['items'][0]);

        $protect = $this->invoke(new RankItems($this->owner), ['strategy' => 'protect', 'months' => ['2026-10']]);
        $this->assertSame(['KOFTA'], array_column($protect['metrics']['segments']['main:single']['items'], 'item_key'));

        $this->assertSame('validation_error', $this->invoke(new RankItems($this->owner), ['strategy' => 'cheapest', 'months' => ['2026-10']])['error']);
    }

    public function test_dates_map_to_the_full_months_they_cover(): void
    {
        $result = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'from' => '2026-09-01', 'to' => '2026-10-31']);
        $this->assertSame(['2026-09', '2026-10'], array_keys($result['metrics']['periods']));

        // last_month is the default period.
        $default = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month']);
        $this->assertSame(['2026-10'], array_keys($default['metrics']['periods']));

        $partial = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'from' => '2026-10-05', 'to' => '2026-10-20']);
        $this->assertSame('insufficient_data', $partial['metrics']['status']);

        $this->assertSame('validation_error', $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'months' => ['2026-12']])['error']);
    }

    public function test_item_card_finds_arabic_spelling_variants_and_returns_the_whole_card(): void
    {
        $tool = new GetItemCard($this->owner);

        $tray = $this->invoke($tool, ['name' => 'صينيه  فراخ', 'months' => ['2026-09', '2026-10']]);
        $this->assertSame('ok', $tray['metrics']['status']);
        $this->assertSame('TRAY', $tray['metrics']['item']['item_key']);
        $this->assertSame(4, $tray['metrics']['item']['serves_n']);
        $this->assertSame('family', $tray['metrics']['item']['price_tier']);
        // Class labels only, largest first and without "other": the mixed-unit quantities are never exposed.
        $this->assertSame(['فراخ', 'رز'], $tray['metrics']['item']['contains']);
        $this->assertSame(1, $tray['metrics']['push_next_month']['2026-10']['rank']);
        $this->assertSame('main:family', $tray['metrics']['push_next_month']['2026-10']['segment']);

        // Hamza and tatweel variants match the exact name.
        $rice = $this->invoke($tool, ['name' => 'ارز بسـمتي', 'months' => ['2026-10']]);
        $this->assertSame('RICE', $rice['metrics']['item']['item_key']);

        $card = $this->invoke($tool, ['item_key' => 'SHAWARMA', 'months' => ['2026-09', '2026-10']]);
        $metrics = $card['metrics'];
        $this->assertSame(['2026-09', '2026-10'], array_keys($metrics['stats']));
        $this->assertEquals(100, $metrics['stats']['2026-10']['qty']);
        $this->assertEquals(50, $metrics['stats']['2026-10']['unit_margin']);
        $this->assertSame(['dine_in', 'delivery'], array_keys($metrics['stats']['2026-10']['channel_mix']));
        $this->assertSame(1, $metrics['push_next_month']['2026-10']['rank']);
        $this->assertSame(2, $metrics['push_next_month']['2026-10']['of']);
        $this->assertSame('2026-10', $metrics['bought_with']['period']);
        $this->assertCount(4, $metrics['bought_with']['itemsets']);
        $this->assertSame(2.5, $metrics['bought_with']['itemsets'][0]['lift']);
        $this->assertSame(40, $metrics['bought_with']['itemsets'][0]['together_orders']);
        $this->assertSame(['approved', 'candidate'], array_column($metrics['combos'], 'status'));
        $this->assertEquals(0.6, $metrics['combos'][0]['checks']['floor']);
        // The card's combos are graded like get_combo_candidates (one main, margin at or above checks.floor).
        $this->assertSame([], app(EvalRunner::class)->gradeRules(
            rules: ['combos_one_main_above_floor'],
            calledTools: ['get_item_card'],
            delegatedAgents: [],
            toolResults: [['tool' => 'get_item_card', 'result' => (string) json_encode($card)]],
            replyText: '',
            grounding: new GroundingResult(grounded: true),
            organizationId: $this->organization->id,
        ));

        $this->assertSame('not_found', $this->invoke($tool, ['name' => 'بيتزا', 'months' => ['2026-10']])['metrics']['status']);
        $ambiguous = $this->invoke($tool, ['name' => 'س', 'months' => ['2026-10']]);
        $this->assertSame('ambiguous', $ambiguous['metrics']['status']);
        $this->assertNotEmpty($ambiguous['metrics']['matches']);
        $this->assertSame('validation_error', $this->invoke($tool, ['months' => ['2026-10']])['error']);
    }

    public function test_combo_candidates_show_approved_first_and_never_rejected(): void
    {
        $tool = new GetComboCandidates($this->owner);
        $payload = $tool->handle(new Request(['hero_name' => 'شاورما', 'months' => ['2026-10'], 'limit' => 6]));
        $result = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('ok', $result['metrics']['status']);
        $combos = $result['metrics']['combos'];
        $this->assertSame(['approved', 'candidate'], array_column($combos, 'status'));
        $this->assertSame('SHAWARMA', $combos[0]['hero']['item_key']);
        $this->assertSame(['main', 'side', 'drink'], array_column($combos[0]['items'], 'role'));
        $this->assertEquals(0.6, $combos[0]['checks']['floor']);
        $this->assertSame('observed', $combos[0]['evidence']['type']);
        $this->assertSame(['أرز بسمتي', 'وجبة شاورما'], $combos[0]['evidence']['itemset']);

        $candidates = $this->invoke($tool, ['hero_key' => 'SHAWARMA', 'months' => ['2026-10'], 'status' => 'candidate']);
        $this->assertSame(['candidate'], array_column($candidates['metrics']['combos'], 'status'));
        $this->assertSame('validation_error', $this->invoke($tool, ['months' => ['2026-10'], 'status' => 'rejected'])['error']);
        $this->assertSame('insufficient_data', $this->invoke($tool, ['hero_key' => 'TRAY', 'months' => ['2026-10']])['metrics']['status']);

        // The eval combo rule reads these candidates: one main, margin at or above checks.floor.
        $grade = fn (string $result): array => app(EvalRunner::class)->gradeRules(
            rules: ['combos_one_main_above_floor'],
            calledTools: ['get_combo_candidates'],
            delegatedAgents: [],
            toolResults: [['tool' => 'get_combo_candidates', 'result' => $result]],
            replyText: '',
            grounding: new GroundingResult(grounded: true),
            organizationId: $this->organization->id,
        );
        $this->assertSame([], $grade($payload));
        $below = $result;
        $below['metrics']['combos'][1]['margin_pct'] = 0.5;
        $this->assertArrayHasKey('combos_one_main_above_floor', $grade((string) json_encode($below)));
    }

    public function test_affinities_return_named_itemsets_with_orders_next_to_lift(): void
    {
        $result = $this->invoke(new GetAffinities($this->owner), ['name' => 'وجبة شاورما', 'months' => ['2026-09', '2026-10'], 'size' => 2]);

        $this->assertSame('ok', $result['metrics']['status']);
        $this->assertSame('2026-10', $result['metrics']['affinity_period']);
        $sets = $result['metrics']['itemsets'];
        $this->assertCount(3, $sets);
        $this->assertSame([2.5, 1.5, 1.2], array_column($sets, 'lift'));
        $this->assertSame(['أرز بسمتي', 'وجبة شاورما'], array_column($sets[0]['items'], 'name'));
        $this->assertSame(40, $sets[0]['together_orders']);
        // A numeric item key still comes back as a string.
        $this->assertSame(['5001', 'SHAWARMA'], array_column($sets[1]['orders_with_each'], 'item_key'));

        $september = $this->invoke(new GetAffinities($this->owner), ['item_key' => 'SHAWARMA', 'months' => ['2026-09']]);
        $this->assertSame('insufficient_data', $september['metrics']['status']);
    }

    public function test_an_unprepared_period_queues_a_build_and_answers_insufficient_data(): void
    {
        Queue::fake();

        $result = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'months' => ['2026-08', '2026-09']]);

        $this->assertSame('insufficient_data', $result['metrics']['status']);
        $this->assertSame(RankItems::PREPARING_REASON, $result['metrics']['reason']);
        Queue::assertPushed(BuildMenuIntelligence::class, fn (BuildMenuIntelligence $job): bool => $job->organizationId === $this->organization->id && $job->periodKeys === ['2026-08'] && $job->classifierCalls === 2);
    }

    public function test_a_period_mid_build_is_still_preparing(): void
    {
        Queue::fake();
        // August's stats are written but its build has not reached the combos step yet.
        $this->stat($this->organization->id, '2026-08', 'SHAWARMA', 100, 8000, 3000);

        $result = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'months' => ['2026-08']]);

        $this->assertSame('insufficient_data', $result['metrics']['status']);
        $this->assertSame(RankItems::PREPARING_REASON, $result['metrics']['reason']);
        Queue::assertPushed(BuildMenuIntelligence::class, fn (BuildMenuIntelligence $job): bool => $job->periodKeys === ['2026-08']);
    }

    public function test_a_built_month_without_sales_says_so_and_queues_nothing(): void
    {
        Queue::fake();
        $this->marker($this->organization->id, '2026-07', 0);

        $result = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'months' => ['2026-07']]);

        $this->assertSame('insufficient_data', $result['metrics']['status']);
        $this->assertSame(RankItems::NO_SALES_REASON, $result['metrics']['reason']);
        Queue::assertNothingPushed();

        // Next to a month with sales, the empty month does not hide the data.
        $mixed = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'months' => ['2026-07', '2026-09']]);
        $this->assertSame('ok', $mixed['metrics']['status']);
        Queue::assertNothingPushed();
    }

    public function test_prepared_at_is_the_latest_build_completion(): void
    {
        DB::table('menu_intelligence_builds')->where('organization_id', $this->organization->id)->where('period_key', '2026-09')->update(['completed_at' => '2026-10-01 04:40:00']);
        DB::table('menu_intelligence_builds')->where('organization_id', $this->organization->id)->where('period_key', '2026-10')->update(['completed_at' => '2026-11-08 04:41:00']);

        $result = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'months' => ['2026-09', '2026-10']]);

        $this->assertSame('2026-11-08 04:41:00', $result['metrics']['prepared_at']);
    }

    public function test_an_unprepared_rolling_window_queues_its_own_build(): void
    {
        Queue::fake();

        $result = $this->invoke(new GetItemCard($this->owner), ['item_key' => 'SHAWARMA', 'period' => 'rolling_90d']);

        $this->assertSame('insufficient_data', $result['metrics']['status']);
        $this->assertSame(['rolling_90d'], array_keys($result['metrics']['periods']));
        Queue::assertPushed(BuildMenuIntelligence::class, fn (BuildMenuIntelligence $job): bool => $job->periodKeys === ['rolling_90d'] && $job->classifierCalls === 2);
    }

    public function test_organizations_never_see_each_others_prepared_rows(): void
    {
        $ranked = $this->invoke(new RankItems($this->otherOwner), ['strategy' => 'push_next_month', 'months' => ['2026-10']]);
        $this->assertSame(['main:single'], array_keys($ranked['metrics']['segments']));
        $this->assertSame('شاورما تانية', $ranked['metrics']['segments']['main:single']['items'][0]['name']);
        $this->assertEquals(7, $ranked['metrics']['segments']['main:single']['items'][0]['qty']);

        $this->assertSame('not_found', $this->invoke(new GetItemCard($this->otherOwner), ['item_key' => 'TRAY', 'months' => ['2026-10']])['metrics']['status']);
        $this->assertSame('insufficient_data', $this->invoke(new GetComboCandidates($this->otherOwner), ['hero_key' => 'SHAWARMA', 'months' => ['2026-10']])['metrics']['status']);
        $this->assertSame('insufficient_data', $this->invoke(new GetAffinities($this->otherOwner), ['item_key' => 'SHAWARMA', 'months' => ['2026-10']])['metrics']['status']);
    }

    public function test_branch_restricted_users_get_no_organization_wide_numbers(): void
    {
        Queue::fake();
        $args = ['months' => ['2026-10'], 'item_key' => 'SHAWARMA'];

        foreach ([new RankItems($this->manager), new GetItemCard($this->manager), new GetComboCandidates($this->manager), new GetAffinities($this->manager)] as $tool) {
            $result = $this->invoke($tool, $args + ['strategy' => 'push_next_month']);
            $this->assertSame('insufficient_data', $result['metrics']['status'], $tool->name());
            $this->assertNotEmpty($result['metrics']['reason']);
            $this->assertStringNotContainsString('8000', (string) json_encode($result));
            $this->assertSame('forbidden_branch', $this->invoke($tool, $args + ['strategy' => 'push_next_month', 'branches' => [$this->branchB->id]])['error']);
        }

        // An owner asking about one branch gets the same answer instead of organization-wide figures.
        $oneBranch = $this->invoke(new RankItems($this->owner), ['strategy' => 'push_next_month', 'months' => ['2026-10'], 'branches' => [$this->branchA->id]]);
        $this->assertSame('insufficient_data', $oneBranch['metrics']['status']);
        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function invoke(Tool $tool, array $args): array
    {
        return json_decode((string) $tool->handle(new Request($args)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{0: Organization, 1: list<Branch>, 2: User} */
    private function organizationWith(int $branches): array
    {
        $organization = Organization::create([
            'name' => 'Org Tools',
            'slug' => 'org-tools-'.Str::random(6),
            'plan' => 'pro',
            'ai_enabled' => true,
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);
        $restaurant = Restaurant::create(['organization_id' => $organization->id, 'name' => 'Restaurant Tools']);
        $list = [];
        for ($i = 1; $i <= $branches; $i++) {
            $list[] = Branch::create([
                'organization_id' => $organization->id,
                'restaurant_id' => $restaurant->id,
                'name' => 'Branch '.$i,
                'timezone' => 'Africa/Cairo',
                'opened_on' => '2026-01-01',
                'status' => BranchStatus::ACTIVE,
            ]);
        }

        return [$organization, $list, $this->user($organization, 'org_owner')];
    }

    private function user(Organization $organization, string $role): User
    {
        $user = User::create([
            'organization_id' => $organization->id,
            'name' => 'User '.$role,
            'email' => $role.'-'.Str::random(6).'@example.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @param  array<string, float>  $contains */
    private function profile(int $org, string $key, string $name, string $role, string $tier, int $serves = 1, array $contains = ['chicken' => 1]): void
    {
        DB::table('item_profiles')->insert([
            'organization_id' => $org,
            'item_key' => $key,
            'name' => $name,
            'category_key' => null,
            'role' => $role,
            'serves_n' => $serves,
            'serves_source' => 'default',
            'price_tier' => $tier,
            'price_tier_source' => 'auto',
            'season' => 'all',
            'season_source' => 'auto',
            'list_price' => 80,
            'unit_cost' => 30,
            'contains' => json_encode($contains),
            'main_protein' => 'chicken',
            'has_recipe' => true,
            'is_existing_combo' => false,
            'combo_members' => null,
            'built_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function marker(int $org, string $period, int $stats): void
    {
        DB::table('menu_intelligence_builds')->insert([
            'organization_id' => $org,
            'period_key' => $period,
            'build_id' => self::BUILD,
            'rows' => json_encode(['stats' => $stats, 'affinities' => 0, 'rankings' => 0, 'combos' => 0]),
            'completed_at' => now(),
        ]);
    }

    private function stat(int $org, string $period, string $key, float $qty, float $net, float $cogs): void
    {
        DB::table('item_period_stats')->insert([
            'organization_id' => $org,
            'period_key' => $period,
            'branch_id' => 0,
            'channel' => 'all',
            'item_key' => $key,
            'qty' => $qty,
            'net_sales' => $net,
            'cogs' => $cogs,
            'gross_profit' => $net - $cogs,
            'open_days' => 30,
            'days_sold' => 28,
            'velocity' => $qty / 30,
            'orders' => (int) $qty,
            'attach_rate' => 0.5,
            'daypart' => json_encode(['morning' => 0.1, 'lunch' => 0.5, 'evening' => 0.3, 'late' => 0.1]),
            'weekday' => json_encode([0.2, 0.1, 0.1, 0.1, 0.1, 0.2, 0.2]),
            'channel_mix' => json_encode(['delivery' => 0.3, 'dine_in' => 0.7]),
            'repeat_customer_share' => null,
            'return_qty' => 0,
            'waste_cost' => 0,
            'mom_velocity_change' => 0.1,
            'build_id' => self::BUILD,
            'created_at' => now(),
        ]);
    }

    private function ranking(int $org, string $period, string $strategy, string $segment, string $key, int $rank, float $score): void
    {
        DB::table('item_rankings')->insert([
            'organization_id' => $org,
            'period_key' => $period,
            'strategy' => $strategy,
            'channel' => 'all',
            'segment_key' => $segment,
            'item_key' => $key,
            'score' => $score,
            'rank' => $rank,
            'quadrant' => 'stars',
            'breakdown' => json_encode(['unit_margin' => ['value' => 0.5, 'weight' => 0.5, 'contribution' => 0.25]]),
            'build_id' => self::BUILD,
            'created_at' => now(),
        ]);
    }

    /** @param list<string> $items */
    private function affinity(int $org, string $period, array $items, int $together, float $lift): void
    {
        sort($items, SORT_STRING);
        DB::table('item_affinities')->insert([
            'organization_id' => $org,
            'period_key' => $period,
            'channel' => 'all',
            'size' => count($items),
            'itemset_hash' => sha1(implode('|', $items)),
            'items' => json_encode($items),
            'member_orders' => json_encode(array_fill_keys($items, 100)),
            'together_orders' => $together,
            'total_orders' => 1000,
            'support' => $together / 1000,
            'lift' => $lift,
            'avg_order_value' => 150,
            'build_id' => self::BUILD,
            'created_at' => now(),
        ]);
    }

    private function combo(int $org, string $period, string $hero, string $status, float $score, string $signature): void
    {
        DB::table('combo_candidates')->insert([
            'organization_id' => $org,
            'period_key' => $period,
            'template' => 'solo_meal',
            'channel' => 'all',
            'hero_key' => $hero,
            'signature' => sha1($signature),
            'items' => json_encode([
                ['key' => $hero, 'name' => 'وجبة شاورما', 'role' => 'main', 'qty' => 1, 'list_price' => 80],
                ['key' => 'RICE', 'name' => 'أرز بسمتي', 'role' => 'side', 'qty' => 1, 'list_price' => 20],
                ['key' => 'COLA', 'name' => 'كولا', 'role' => 'drink', 'qty' => 1, 'list_price' => 10],
            ]),
            'list_total' => 110,
            'cost_total' => 38,
            'suggested_price' => 99,
            'margin_pct' => 0.6162,
            'discount_pct' => 0.1,
            'evidence' => json_encode(['type' => 'observed', 'itemset' => ['RICE', 'SHAWARMA'], 'together_orders' => 40, 'support' => 0.04, 'lift' => 2.5, 'why' => 'الأصناف دي اتطلبت مع بعض في 40 طلب.']),
            'checks' => json_encode(['redundant_rejected' => [], 'unknown_recipe' => [], 'drinks_match_serves' => true, 'floor' => 0.6]),
            'overlaps_combo_key' => null,
            'score' => $score,
            'status' => $status,
            'owner_note' => null,
            'build_id' => self::BUILD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
