<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Domain\Menu\Intelligence\IntelligenceWriter;
use App\Models\Menu\ItemPeriodStat;
use App\Models\Menu\ItemProfile;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class IntelligenceWriterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function stat(string $key, float $qty, string $buildId = '01JBUILD0000000000000000AA'): array
    {
        return [
            'item_key' => $key,
            'qty' => $qty,
            'net_sales' => $qty * 10,
            'cogs' => $qty * 4,
            'gross_profit' => $qty * 6,
            'open_days' => 30,
            'days_sold' => 28,
            'velocity' => $qty / 30,
            'orders' => (int) $qty,
            'daypart' => ['lunch' => 0.6, 'dinner' => 0.4],
            'weekday' => [0 => 0.1, 6 => 0.2],
            'build_id' => $buildId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(string $key, int $servesN = 1, string $priceTier = 'single', string $season = 'all'): array
    {
        return [
            'item_key' => $key,
            'name' => 'Item '.$key,
            'category_key' => 'mains',
            'role' => 'main',
            'serves_n' => $servesN,
            'serves_source' => 'recipe',
            'price_tier' => $priceTier,
            'price_tier_source' => 'auto',
            'season' => $season,
            'season_source' => 'auto',
            'list_price' => 100,
            'unit_cost' => 40,
            'contains' => ['chicken' => 0.25, 'rice' => 0.2],
            'main_protein' => 'chicken',
            'has_recipe' => true,
            'is_existing_combo' => false,
            'combo_members' => null,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function statQty(int $orgId, string $period): array
    {
        return ItemPeriodStat::query()->withoutGlobalScopes()
            ->where('organization_id', $orgId)->where('period_key', $period)
            ->orderBy('item_key')->pluck('qty', 'item_key')->all();
    }

    public function test_replace_swaps_rows_for_one_org_and_period_only(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        IntelligenceWriter::replace('item_period_stats', $org->id, '2026-10', [$this->stat('A', 10), $this->stat('B', 20)]);
        IntelligenceWriter::replace('item_period_stats', $org->id, '2026-09', [$this->stat('A', 5)]);
        IntelligenceWriter::replace('item_period_stats', $other->id, '2026-10', [$this->stat('A', 7)]);

        $written = IntelligenceWriter::replace('item_period_stats', $org->id, '2026-10', [$this->stat('C', 30, '01JBUILD0000000000000000BB')]);

        $this->assertSame(1, $written);
        $this->assertSame(['C' => 30.0], $this->statQty($org->id, '2026-10'));
        $this->assertSame(['A' => 5.0], $this->statQty($org->id, '2026-09'));
        $this->assertSame(['A' => 7.0], $this->statQty($other->id, '2026-10'));

        $row = ItemPeriodStat::query()->withoutGlobalScopes()->where('organization_id', $org->id)->where('period_key', '2026-10')->firstOrFail();
        $this->assertSame(['lunch' => 0.6, 'dinner' => 0.4], $row->daypart);
        $this->assertSame(0, $row->branch_id);
        $this->assertSame('all', $row->channel);
        $this->assertSame('01JBUILD0000000000000000BB', $row->build_id);
        $this->assertNotNull($row->created_at);
    }

    public function test_replace_inserts_in_chunks_and_counts_every_row(): void
    {
        $org = Organization::factory()->create();
        $rows = (function () {
            for ($i = 0; $i < 1203; $i++) {
                yield $this->stat(sprintf('K%04d', $i), 1);
            }
        })();

        $this->assertSame(1203, IntelligenceWriter::replace('item_period_stats', $org->id, 'rolling_90d', $rows));
        $this->assertSame(1203, ItemPeriodStat::query()->withoutGlobalScopes()->where('organization_id', $org->id)->count());
    }

    public function test_replace_rejects_a_row_with_extra_columns_and_keeps_the_old_rows(): void
    {
        $org = Organization::factory()->create();
        IntelligenceWriter::replace('item_period_stats', $org->id, '2026-10', [$this->stat('OLD', 1)]);

        $extra = $this->stat('B', 2) + ['attach_rate' => 0.5];

        try {
            IntelligenceWriter::replace('item_period_stats', $org->id, '2026-10', [$this->stat('A', 1), $extra]);
            $this->fail('A row with extra columns must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Missing: []. Extra: [attach_rate]', $e->getMessage());
        }

        $this->assertSame(['OLD' => 1.0], $this->statQty($org->id, '2026-10'));
    }

    public function test_replace_profiles_rejects_a_row_with_missing_columns_and_keeps_the_old_rows(): void
    {
        $org = Organization::factory()->create();
        IntelligenceWriter::replaceProfiles($org->id, [$this->profile('OLD')]);

        $missing = $this->profile('B');
        unset($missing['main_protein'], $missing['combo_members']);

        try {
            IntelligenceWriter::replaceProfiles($org->id, [$this->profile('A'), $missing]);
            $this->fail('A row with missing columns must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Missing: [main_protein, combo_members]. Extra: []', $e->getMessage());
        }

        $this->assertSame(['OLD'], ItemProfile::query()->withoutGlobalScopes()->where('organization_id', $org->id)->pluck('item_key')->all());
    }

    public function test_replace_rejects_tables_without_a_period_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        IntelligenceWriter::replace('item_profiles', 1, '2026-10', []);
    }

    public function test_replace_profiles_keeps_owner_fields_overwrites_auto_and_deletes_stale(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();

        IntelligenceWriter::replaceProfiles($org->id, [$this->profile('A'), $this->profile('B'), $this->profile('STALE')]);
        IntelligenceWriter::replaceProfiles($other->id, [$this->profile('A')]);

        // The owner overrides A's serves_n, price tier and season in the UI.
        ItemProfile::query()->withoutGlobalScopes()->where('organization_id', $org->id)->where('item_key', 'A')->update([
            'serves_n' => 4, 'serves_source' => 'owner',
            'price_tier' => 'family', 'price_tier_source' => 'owner',
            'season' => 'winter', 'season_source' => 'owner',
        ]);

        $rebuilt = $this->profile('A', 2, 'sharing', 'summer');
        $rebuilt['name'] = 'Renamed A';
        $rebuilt['list_price'] = 120;

        $written = IntelligenceWriter::replaceProfiles($org->id, [$rebuilt, $this->profile('B', 3, 'family', 'ramadan'), $this->profile('NEW')]);

        $this->assertSame(3, $written);

        $profiles = ItemProfile::query()->withoutGlobalScopes()->where('organization_id', $org->id)->get()->keyBy('item_key');
        $this->assertEqualsCanonicalizing(['A', 'B', 'NEW'], $profiles->keys()->all());

        $a = $profiles['A'];
        $this->assertSame([4, 'owner', 'family', 'owner', 'winter', 'owner'], [$a->serves_n, $a->serves_source, $a->price_tier, $a->price_tier_source, $a->season, $a->season_source]);
        $this->assertSame('Renamed A', $a->name);
        $this->assertSame(120.0, $a->list_price);
        $this->assertSame(['chicken' => 0.25, 'rice' => 0.2], $a->contains);
        $this->assertTrue($a->built_at->greaterThan(now()->subMinute()), 'built_at defaults to the write time');

        $b = $profiles['B'];
        $this->assertSame([3, 'recipe', 'family', 'auto', 'ramadan', 'auto'], [$b->serves_n, $b->serves_source, $b->price_tier, $b->price_tier_source, $b->season, $b->season_source]);

        // Another org's profiles are untouched.
        $this->assertSame(['A'], ItemProfile::query()->withoutGlobalScopes()->where('organization_id', $other->id)->pluck('item_key')->all());
    }
}
