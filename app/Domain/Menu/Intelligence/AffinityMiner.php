<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Models\Menu\ItemAffinity;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Basket itemsets of size 2..max_itemset_size per period and channel ('all' plus each order type
 * with enough multi-item baskets), mined with Apriori over the completed orders of every branch.
 * Adjustment and raw lines are dropped from baskets; every other item stays, so drinks, sides and
 * add-ons show up as partners.
 *
 * The period's completed orders are read in id-ordered chunks (affinity_chunk_orders orders each),
 * loading each chunk's item keys with a bounded query; channel scopes are cut from the baskets in memory.
 * Runs in queue jobs without a user: every query is org-filtered by hand.
 */
final class AffinityMiner
{
    public const CHANNEL_ALL = 'all';

    private const ORDER_ITEM_KEY = 'COALESCE(i.product_ref, i.product_uuid)';

    private const EXCLUDED_ROLES = [ItemRole::Adjustment, ItemRole::Raw];

    /** decimal(12,4) ceiling for lift: rare 4-item sets in a large period can exceed it. */
    private const MAX_LIFT = 99999999.9999;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;

    /** Orders whose item keys are loaded per query (menu.intelligence.affinity_chunk_orders when null). */
    private readonly int $chunkOrders;

    public function __construct(private readonly ItemRoles $roles, ?int $chunkOrders = null)
    {
        $this->chunkOrders = max(1, $chunkOrders ?? (int) config('menu.intelligence.affinity_chunk_orders', 2000));
    }

    /** Number of rows written for the period (its previous rows are replaced). */
    public function build(Organization $org, string $periodKey, CarbonImmutable $today, string $buildId): int
    {
        $orgId = $org->id;
        [$from, $to] = PeriodKeys::range($periodKey, $today);
        $data = $this->load($orgId, $from, $to);

        $minBaskets = (int) config('menu.intelligence.min_baskets');
        $multi = [self::CHANNEL_ALL => count($data['baskets'])] + array_count_values($data['types']);

        $rows = [];
        foreach ($multi as $channel => $baskets) {
            $channel = (string) $channel;
            if ($baskets < $minBaskets) {
                continue;
            }

            $indexes = $channel === self::CHANNEL_ALL
                ? array_keys($data['baskets'])
                : array_keys($data['types'], $channel, true);

            $scopeBaskets = [];
            $scopeValues = [];
            foreach ($indexes as $i) {
                $scopeBaskets[] = $data['baskets'][$i];
                $scopeValues[] = $data['values'][$i];
            }

            $total = $data['totals'][$channel];
            $members = $data['members'][$channel];
            $minCount = max((int) config('menu.intelligence.min_support_orders'), (int) ceil((float) config('menu.intelligence.min_support_ratio') * $total));

            foreach ($this->apriori($scopeBaskets, $scopeValues, $members, $minCount, (int) config('menu.intelligence.max_itemset_size')) as $itemset) {
                $rows[] = $this->row($channel, $itemset, $data['keys'], $members, $total, $buildId);
            }
        }

        return IntelligenceWriter::replace('item_affinities', $orgId, $periodKey, $rows);
    }

    /**
     * Itemsets of one scope that contain the item, strongest first (lift, then together orders).
     * Filtered in PHP: there is no JSON indexing on MariaDB 10.4.
     *
     * @return Collection<int, ItemAffinity>
     */
    public static function forItem(int $orgId, string $periodKey, string $itemKey, string $channel = self::CHANNEL_ALL, ?int $size = null): Collection
    {
        return ItemAffinity::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('period_key', $periodKey)
            ->where('channel', $channel)
            ->when($size !== null, fn ($query) => $query->where('size', $size))
            ->get()
            ->filter(fn (ItemAffinity $affinity): bool => in_array($itemKey, $affinity->items, true))
            ->sort(fn (ItemAffinity $a, ItemAffinity $b): int => [$b->lift, $b->together_orders] <=> [$a->lift, $a->together_orders])
            ->values();
    }

    /**
     * @param  array{items: list<int>, together: int, value: float}  $itemset
     * @param  list<string>  $keys  item id => item key
     * @param  array<int, int>  $members  item id => orders containing it
     * @return array<string, mixed>
     */
    private function row(string $channel, array $itemset, array $keys, array $members, int $total, string $buildId): array
    {
        $memberOrders = [];
        $expected = 1.0;
        foreach ($itemset['items'] as $id) {
            $memberOrders[$keys[$id]] = $members[$id];
            $expected *= $members[$id] / $total;
        }
        ksort($memberOrders, SORT_STRING);
        $items = array_map(fn ($key): string => (string) $key, array_keys($memberOrders));
        $support = $itemset['together'] / $total;

        return [
            'channel' => $channel,
            'size' => count($items),
            'itemset_hash' => sha1(implode('|', $items)),
            'items' => $items,
            // Pre-encoded so numeric item keys stay JSON object keys.
            'member_orders' => json_encode((object) $memberOrders, self::JSON_FLAGS),
            'together_orders' => $itemset['together'],
            'total_orders' => $total,
            'support' => round($support, 8),
            'lift' => round(min($support / $expected, self::MAX_LIFT), 4),
            'avg_order_value' => round($itemset['value'] / $itemset['together'], 2),
            'build_id' => $buildId,
        ];
    }

    /**
     * Every completed order of the period as a basket of distinct item ids (adjustment/raw dropped).
     * Totals and member counts cover every order; only baskets of 2+ items are kept for mining.
     *
     * @return array{keys: list<string>, totals: array<string, int>, members: array<string, array<int, int>>, baskets: list<list<int>>, types: list<string>, values: list<float>}
     */
    private function load(int $orgId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $data = ['keys' => [], 'totals' => [self::CHANNEL_ALL => 0], 'members' => [self::CHANNEL_ALL => []], 'baskets' => [], 'types' => [], 'values' => []];

        $branchIds = DB::table('branches')->where('organization_id', $orgId)->pluck('id')->all();
        if ($branchIds === []) {
            return $data;
        }

        $roles = $this->roles->mapFor($orgId);
        $ids = [];
        $keep = [];

        $periodOrders = DB::table('wh_orders as o')
            ->where('o.organization_id', $orgId)
            ->whereIn('o.branch_id', $branchIds)
            ->whereBetween('o.business_date', [$from->toDateString(), $to->toDateString()])
            ->where('o.status', 'completed')
            ->whereNull('o.deleted_at');

        // Order ids first, then each chunk's lines: no unbuffered cursor holds the connection, and memory stays bounded.
        (clone $periodOrders)->select('o.id')->chunkById($this->chunkOrders, function (Collection $chunk) use ($periodOrders, $roles, &$ids, &$keep, &$data): void {
            $lines = (clone $periodOrders)
                ->join('wh_order_items as i', fn ($join) => $join->on('i.organization_id', '=', 'o.organization_id')->on('i.branch_id', '=', 'o.branch_id')->on('i.order_uuid', '=', 'o.uuid'))
                ->whereIn('o.id', $chunk->pluck('id')->all())
                ->whereRaw(self::ORDER_ITEM_KEY.' IS NOT NULL')
                ->distinct()
                ->selectRaw('o.id, o.type, o.total, '.self::ORDER_ITEM_KEY.' AS item_key')
                ->orderBy('o.id')
                ->get();

            foreach ($lines->groupBy('id') as $orderLines) {
                /** @var stdClass $first */
                $first = $orderLines->first();
                $type = (string) $first->type;

                $basket = [];
                foreach ($orderLines as $line) {
                    $key = (string) $line->item_key;
                    $keep[$key] ??= ! in_array($roles->roleOf($key), self::EXCLUDED_ROLES, true);
                    if (! $keep[$key]) {
                        continue;
                    }
                    if (! isset($ids[$key])) {
                        $ids[$key] = count($data['keys']);
                        $data['keys'][] = $key;
                    }
                    $basket[] = $ids[$key];
                }

                foreach ([self::CHANNEL_ALL, $type] as $channel) {
                    $data['totals'][$channel] = ($data['totals'][$channel] ?? 0) + 1;
                    $data['members'][$channel] ??= [];
                    foreach ($basket as $id) {
                        $data['members'][$channel][$id] = ($data['members'][$channel][$id] ?? 0) + 1;
                    }
                }

                if (count($basket) >= 2) {
                    sort($basket);
                    $data['baskets'][] = $basket;
                    $data['types'][] = $type;
                    $data['values'][] = (float) $first->total;
                }
            }
        }, 'o.id', 'id');

        return $data;
    }

    /**
     * Frequent itemsets of size 2..$maxSize. A k-set is only counted when every (k-1)-subset is
     * frequent; each basket is first trimmed to items still part of a frequent (k-1)-set.
     *
     * @param  list<list<int>>  $baskets  sorted item ids, 2+ items each
     * @param  list<float>  $values  order total per basket
     * @param  array<int, int>  $members  item id => orders containing it
     * @return list<array{items: list<int>, together: int, value: float}>
     */
    private function apriori(array $baskets, array $values, array $members, int $minCount, int $maxSize): array
    {
        $alive = array_map(fn (): bool => true, array_filter($members, fn (int $n): bool => $n >= $minCount));
        $frequent = [];
        $candidates = [];

        for ($k = 2; $k <= $maxSize && count($alive) >= $k; $k++) {
            $counts = [];
            $sums = [];

            foreach ($baskets as $b => $basket) {
                $items = array_values(array_filter($basket, fn (int $id): bool => isset($alive[$id])));
                if (count($items) < $k) {
                    continue;
                }

                foreach ($this->matches($items, $k, $candidates) as $key) {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                    $sums[$key] = ($sums[$key] ?? 0.0) + $values[$b];
                }
            }

            $level = [];
            $alive = [];
            foreach ($counts as $key => $n) {
                if ($n < $minCount) {
                    continue;
                }
                $items = array_map('intval', explode('|', (string) $key));
                $level[] = $items;
                $frequent[] = ['items' => $items, 'together' => $n, 'value' => $sums[$key]];
                foreach ($items as $id) {
                    $alive[$id] = true;
                }
            }

            $candidates = self::candidates($level);
            if ($candidates === []) {
                break;
            }
        }

        return $frequent;
    }

    /**
     * Keys of the k-sets in the basket that are counted: every pair for k = 2, else the candidates
     * it contains (enumerating whichever side is smaller).
     *
     * @param  list<int>  $items  sorted
     * @param  array<string, list<int>>  $candidates
     * @return iterable<string>
     */
    private function matches(array $items, int $k, array $candidates): iterable
    {
        if ($k > 2 && self::binomial(count($items), $k) > count($candidates)) {
            $present = array_flip($items);
            foreach ($candidates as $key => $ids) {
                foreach ($ids as $id) {
                    if (! isset($present[$id])) {
                        continue 2;
                    }
                }
                yield $key;
            }

            return;
        }

        foreach (self::combinations($items, $k) as $combo) {
            $key = implode('|', $combo);
            if ($k === 2 || isset($candidates[$key])) {
                yield $key;
            }
        }
    }

    /**
     * (k+1)-set candidates from the frequent k-sets: join sets sharing their first k-1 items, then
     * keep only those whose every k-subset is frequent.
     *
     * @param  list<list<int>>  $level  frequent k-sets, sorted ids
     * @return array<string, list<int>> key => sorted ids
     */
    private static function candidates(array $level): array
    {
        $frequent = [];
        $groups = [];
        foreach ($level as $items) {
            $frequent[implode('|', $items)] = true;
            $last = array_pop($items);
            $groups[implode('|', $items)]['prefix'] = $items;
            $groups[implode('|', $items)]['lasts'][] = $last;
        }

        $candidates = [];
        foreach ($groups as $group) {
            $lasts = $group['lasts'];
            sort($lasts);
            for ($i = 0, $n = count($lasts); $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $items = [...$group['prefix'], $lasts[$i], $lasts[$j]];
                    foreach (array_keys($items) as $drop) {
                        $subset = $items;
                        unset($subset[$drop]);
                        if (! isset($frequent[implode('|', $subset)])) {
                            continue 2;
                        }
                    }
                    $candidates[implode('|', $items)] = $items;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  list<int>  $items
     * @param  list<int>  $prefix
     * @return Generator<int, list<int>>
     */
    private static function combinations(array $items, int $k, int $start = 0, array $prefix = []): Generator
    {
        if ($k === 0) {
            yield $prefix;

            return;
        }

        for ($i = $start, $n = count($items); $i <= $n - $k; $i++) {
            yield from self::combinations($items, $k - 1, $i + 1, [...$prefix, $items[$i]]);
        }
    }

    private static function binomial(int $n, int $k): int
    {
        $result = 1;
        for ($i = 1; $i <= $k; $i++) {
            $result = intdiv($result * ($n - $k + $i), $i);
        }

        return $result;
    }
}
