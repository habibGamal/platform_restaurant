<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

use App\Domain\Menu\ItemRole;
use App\Domain\Menu\MenuQuadrants;
use App\Models\Menu\ItemRanking;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ranked item lists per strategy, within segments of comparable items ("<role>:<price_tier>"), so a
 * family tray never competes with a single sandwich. Channel 'all' gets every configured strategy;
 * every other channel gets push_next_month only, from that channel's own stats.
 *
 * Factors are 0..1 percentiles within the segment (see constraints); quadrants come from
 * MenuQuadrants per segment. Runs in queue jobs without a user: every query is org-filtered by hand.
 *
 * @phpstan-type Item array{key: string, name: string, role: string, segment: string, season: string, qty: float, net_sales: float, cogs: float, gross_profit: float, momentum: float|null, repeat: float|null}
 * @phpstan-type Factor array{value: float, weight: float, contribution: float}
 * @phpstan-type TopEntry array{item_key: string, name: string, segment_key: string, quadrant: string, score: float, breakdown: array<string, Factor>|null, per_period: array<string, float>}
 */
final class ItemRanker
{
    public const CHANNEL_ALL = 'all';

    public const PUSH_NEXT_MONTH = 'push_next_month';

    /** Number of rows written for the period (its previous rows are replaced). */
    public function build(Organization $org, string $periodKey, CarbonImmutable $today, string $buildId): int
    {
        $orgId = $org->id;
        $strategies = (array) config('menu.strategies');
        $activeSeasons = self::activeSeasons(PeriodKeys::range($periodKey, $today)[1]);

        $rows = [];
        foreach ($this->eligible($orgId, $periodKey) as $channel => $segments) {
            $channelStrategies = $channel === self::CHANNEL_ALL
                ? $strategies
                : array_intersect_key($strategies, [self::PUSH_NEXT_MONTH => true]);

            foreach ($segments as $segment => $items) {
                $quadrants = $this->quadrantOf($items);
                $factors = $this->factors($items, $activeSeasons);

                foreach ($channelStrategies as $strategy => $config) {
                    /** @var list<string>|null $only */
                    $only = $config['quadrants'];
                    /** @var array<string, float> $weights */
                    $weights = $config['weights'];

                    $ranked = [];
                    foreach ($items as $key => $item) {
                        if ($only !== null && ! in_array($quadrants[$key], $only, true)) {
                            continue;
                        }

                        $breakdown = [];
                        $score = 0.0;
                        foreach ($weights as $factor => $weight) {
                            $value = round($factors[$key][$factor], 4);
                            $contribution = round($weight * $value, 4);
                            $breakdown[$factor] = ['value' => $value, 'weight' => (float) $weight, 'contribution' => $contribution];
                            $score += $contribution;
                        }

                        // Numeric item keys come back as int array keys.
                        $ranked[] = ['key' => (string) $key, 'score' => round($score, 4), 'gross_profit' => $item['gross_profit'], 'breakdown' => $breakdown];
                    }

                    usort($ranked, fn (array $a, array $b): int => [$b['score'], $b['gross_profit'], $a['key']] <=> [$a['score'], $a['gross_profit'], $b['key']]);

                    foreach ($ranked as $i => $entry) {
                        $rows[] = [
                            'strategy' => (string) $strategy,
                            'channel' => $channel,
                            'segment_key' => $segment,
                            'item_key' => $entry['key'],
                            'score' => $entry['score'],
                            'rank' => $i + 1,
                            'quadrant' => $quadrants[$entry['key']],
                            'breakdown' => $entry['breakdown'],
                            'build_id' => $buildId,
                        ];
                    }
                }
            }
        }

        return IntelligenceWriter::replace('item_rankings', $orgId, $periodKey, $rows);
    }

    /**
     * Ranked entries for one strategy and channel. One period: that period's rows in rank order.
     * Several periods: the mean score across them (missing from a period = 0 there), best first.
     *
     * @param  list<string>  $periodKeys
     * @return list<TopEntry>
     */
    public function top(int $orgId, array $periodKeys, string $strategy, string $channel = self::CHANNEL_ALL, ?string $segmentKey = null, int $limit = 10): array
    {
        $periodKeys = array_values(array_unique($periodKeys));
        if ($periodKeys === [] || $limit <= 0) {
            return [];
        }

        $rankings = ItemRanking::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->whereIn('period_key', $periodKeys)
            ->where('strategy', $strategy)
            ->where('channel', $channel)
            ->when($segmentKey !== null, fn ($q) => $q->where('segment_key', $segmentKey))
            ->orderBy('rank')
            ->orderByDesc('score')
            ->orderBy('item_key')
            ->get();

        /** @var array<string, array<string, ItemRanking>> $byItem item key => period key => row */
        $byItem = [];
        foreach ($rankings as $ranking) {
            $byItem[$ranking->item_key][$ranking->period_key] = $ranking;
        }

        $single = count($periodKeys) === 1;
        $entries = [];
        foreach ($byItem as $key => $periods) {
            $perPeriod = [];
            $latest = null;
            foreach ($periodKeys as $period) {
                $perPeriod[$period] = isset($periods[$period]) ? $periods[$period]->score : 0.0;
                $latest = $periods[$period] ?? $latest;
            }

            /** @var ItemRanking $latest */
            $entries[] = [
                'item_key' => (string) $key,
                'name' => (string) $key,
                'segment_key' => $latest->segment_key,
                'quadrant' => $latest->quadrant,
                'score' => round(array_sum($perPeriod) / count($perPeriod), 4),
                'breakdown' => $single ? $latest->breakdown : null,
                'per_period' => $perPeriod,
            ];
        }

        // One period keeps the query's rank order; several sort by the mean score.
        if (! $single) {
            usort($entries, fn (array $a, array $b): int => [$b['score'], $a['item_key']] <=> [$a['score'], $b['item_key']]);
        }
        $entries = array_slice($entries, 0, $limit);

        $names = DB::table('item_profiles')
            ->where('organization_id', $orgId)
            ->whereIn('item_key', array_column($entries, 'item_key'))
            ->pluck('name', 'item_key')
            ->all();

        foreach ($entries as $i => $entry) {
            $entries[$i]['name'] = isset($names[$entry['item_key']]) ? (string) $names[$entry['item_key']] : $entry['item_key'];
        }

        return $entries;
    }

    /**
     * Seasons active in the calendar month after the period's last day. Winter / summer by month
     * number; ramadan / fasting when one of their date ranges overlaps that month.
     *
     * @return list<string> Season values
     */
    public static function activeSeasons(CarbonImmutable $periodEnd): array
    {
        $from = $periodEnd->startOfMonth()->addMonthNoOverflow()->startOfDay();
        $to = $from->endOfMonth()->startOfDay();
        $month = $from->month;

        $active = [];
        if (in_array($month, (array) config('menu.seasons.winter_months'), true)) {
            $active[] = Season::Winter->value;
        }
        if (in_array($month, (array) config('menu.seasons.summer_months'), true)) {
            $active[] = Season::Summer->value;
        }
        foreach ([Season::Ramadan, Season::Fasting] as $season) {
            foreach ((array) config('menu.seasons.'.$season->value) as $range) {
                [$start, $end] = $range;
                if ((string) $start <= $to->toDateString() && (string) $end >= $from->toDateString()) {
                    $active[] = $season->value;
                    break;
                }
            }
        }

        return $active;
    }

    /**
     * Percentile of each value among the given values: (below + 0.5 × (equal − 1)) / max(n − 1, 1).
     * A lone value gets 0.5, the segment's neutral middle.
     *
     * @param  array<string, float>  $values
     * @return array<string, float>
     */
    public static function percentiles(array $values): array
    {
        $n = count($values);
        if ($n === 1) {
            return array_map(fn (): float => 0.5, $values);
        }

        // Rounded so values equal on paper but apart by float noise count as ties.
        $values = array_map(fn (float $v): float => round($v, 9), $values);
        $sorted = array_values($values);
        sort($sorted);

        $result = [];
        foreach ($values as $key => $value) {
            $below = 0;
            $equal = 0;
            foreach ($sorted as $other) {
                if ($other < $value) {
                    $below++;
                } elseif ($other == $value) {
                    $equal++;
                } else {
                    break;
                }
            }
            $result[$key] = ($below + 0.5 * ($equal - 1)) / max($n - 1, 1);
        }

        return $result;
    }

    /**
     * Eligible items per channel and segment: branch_id 0 stats joined to the item's profile.
     *
     * @return array<string, array<string, non-empty-array<string, Item>>> channel => segment => item key => item
     */
    private function eligible(int $orgId, string $periodKey): array
    {
        $profiles = DB::table('item_profiles')
            ->where('organization_id', $orgId)
            ->get(['item_key', 'name', 'role', 'price_tier', 'season'])
            ->keyBy('item_key');

        $stats = DB::table('item_period_stats')
            ->where('organization_id', $orgId)
            ->where('period_key', $periodKey)
            ->where('branch_id', 0)
            ->orderBy('channel')
            ->orderBy('item_key')
            ->get(['channel', 'item_key', 'qty', 'net_sales', 'cogs', 'gross_profit', 'days_sold', 'mom_velocity_change', 'repeat_customer_share']);

        $minDays = (int) config('menu.intelligence.min_days_on_sale');
        $eligible = [];

        foreach ($stats as $stat) {
            $profile = $profiles->get($stat->item_key);
            $role = $profile !== null && $profile->role !== null ? ItemRole::tryFrom((string) $profile->role) : null;
            $qty = (float) $stat->qty;
            $cogs = (float) $stat->cogs;

            if ($role === null || ! $role->isMarketable() || (int) $stat->days_sold < $minDays || $cogs <= 0.0 || $qty <= 0.0) {
                continue;
            }

            $key = (string) $stat->item_key;
            $segment = $role->value.':'.$profile->price_tier;
            $eligible[(string) $stat->channel][$segment][$key] = [
                'key' => $key,
                'name' => (string) $profile->name,
                'role' => $role->value,
                'segment' => $segment,
                'season' => (string) $profile->season,
                'qty' => $qty,
                'net_sales' => (float) $stat->net_sales,
                'cogs' => $cogs,
                'gross_profit' => (float) $stat->gross_profit,
                'momentum' => $stat->mom_velocity_change !== null ? (float) $stat->mom_velocity_change : null,
                'repeat' => $stat->repeat_customer_share !== null ? (float) $stat->repeat_customer_share : null,
            ];
        }

        return $eligible;
    }

    /**
     * @param  non-empty-array<string, Item>  $items
     * @return array<string, string> item key => quadrant
     */
    private function quadrantOf(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = ['key' => $item['key'], 'name' => $item['name'], 'role' => $item['role'], 'qty' => $item['qty'], 'net_sales' => $item['net_sales'], 'cogs' => $item['cogs']];
        }

        $quadrantOf = [];
        foreach (MenuQuadrants::classify($rows)[0] as $quadrant => $members) {
            foreach ($members as $member) {
                $quadrantOf[$member['key']] = $quadrant;
            }
        }

        return $quadrantOf;
    }

    /**
     * Every factor of every item in one segment.
     *
     * @param  non-empty-array<string, Item>  $items
     * @param  list<string>  $activeSeasons
     * @return array<string, array<string, float>> item key => factor => 0..1
     */
    private function factors(array $items, array $activeSeasons): array
    {
        $segmentQty = array_sum(array_column($items, 'qty'));

        $unitMargin = self::percentiles(array_map(fn (array $i): float => ($i['net_sales'] - $i['cogs']) / $i['qty'], $items));
        $popularity = self::percentiles(array_map(fn (array $i): float => $i['qty'] / $segmentQty, $items));
        $momentum = self::nullablePercentiles(array_map(fn (array $i): ?float => $i['momentum'], $items));
        $repeat = self::nullablePercentiles(array_map(fn (array $i): ?float => $i['repeat'], $items));
        $marginPct = self::nullablePercentiles(array_map(
            fn (array $i): ?float => $i['net_sales'] > 0 ? ($i['net_sales'] - $i['cogs']) / $i['net_sales'] : null,
            $items,
        ));

        $factors = [];
        foreach ($items as $key => $item) {
            $factors[$key] = [
                'unit_margin' => $unitMargin[$key],
                'popularity' => $popularity[$key],
                'headroom' => 1 - $popularity[$key],
                'momentum' => $momentum[$key],
                'season_fit' => match (true) {
                    in_array($item['season'], $activeSeasons, true) => 1.0,
                    $item['season'] === Season::All->value => 0.5,
                    default => 0.0,
                },
                'repeat' => $repeat[$key],
                'low_margin_pct' => 1 - $marginPct[$key],
                'low_popularity' => 1 - $popularity[$key],
                'low_unit_margin' => 1 - $unitMargin[$key],
                'low_momentum' => 1 - $momentum[$key],
            ];
        }

        return $factors;
    }

    /**
     * Percentiles among the known values only; an unknown value is neutral (0.5).
     *
     * @param  array<string, float|null>  $values
     * @return array<string, float>
     */
    private static function nullablePercentiles(array $values): array
    {
        $known = array_filter($values, fn (?float $v): bool => $v !== null);
        $ranked = $known === [] ? [] : self::percentiles($known);

        return array_replace(array_map(fn (): float => 0.5, $values), $ranked);
    }
}
