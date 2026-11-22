<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

use App\Domain\Menu\ItemRole;
use App\Models\Menu\ComboCandidate;
use App\Models\Menu\ComponentClass;
use App\Models\Menu\ItemAffinity;
use App\Models\Menu\ItemProfile;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Priced combo proposals per period and channel ('all' plus every channel with basket itemsets),
 * one template at a time: the best push_next_month heroes of the template's tier, with each slot
 * filled by the partner most often bought with the hero (falling back to the partner's own orders).
 *
 * A partner whose main food class the hero already contains is rejected (rice beside a rice tray);
 * prices come from the shared discount ladder, held at the hero segment's margin floor. Owner
 * decisions follow a candidate across rebuilds through its signature.
 *
 * Runs in queue jobs without a user: every query is org-filtered by hand.
 *
 * @phpstan-type Profile array{key: string, name: string, role: ItemRole|null, tier: string, serves_n: int, list_price: float, unit_cost: float, contains: array<string, float>, top_class: string|null, has_recipe: bool, is_existing_combo: bool}
 * @phpstan-type Itemset array{items: list<string>, size: int, member_orders: array<string, int>, together: int, support: float, lift: float}
 * @phpstan-type Slot array{roles: list<string>, qty: int|string, prefer_classes?: list<string>, optional?: bool}
 * @phpstan-type Ranked array{allowed: list<string>, rejected: list<string>}
 */
final class ComboCandidateGenerator
{
    public const CHANNEL_ALL = 'all';

    private const STATUS_CANDIDATE = 'candidate';

    /** Evidence strength of a rule-based (unobserved) candidate. */
    private const RULE_STRENGTH = 0.2;

    /** Lift at which observed evidence counts as full strength. */
    private const FULL_LIFT = 3.0;

    /** Margin at which the margin term of the score is full. */
    private const FULL_MARGIN = 0.6;

    public function __construct(private readonly ItemProfileBuilder $profileBuilder) {}

    /** Number of rows written for the period (its previous rows are replaced). */
    public function build(Organization $org, string $periodKey, CarbonImmutable $today, string $buildId): int
    {
        $orgId = $org->id;
        $profiles = $this->profiles($orgId);
        $push = $this->pushScores($orgId, $periodKey);
        $orders = $this->orders($orgId, $periodKey);
        $floors = $this->segmentFloors($orgId, $periodKey, $profiles);
        $itemsets = $this->itemsets($orgId, $periodKey);
        $channels = array_values(array_unique([self::CHANNEL_ALL, ...array_map('strval', array_keys($itemsets))]));

        /** @var array<string, array{hero_tier: string, slots: list<Slot>}> $templates */
        $templates = (array) config('menu.templates');
        $perTemplate = (int) config('menu.intelligence.combo_heroes_per_template');

        // Heroes per channel and template, best push score first.
        $heroes = [];
        $partnerRoles = [];
        foreach ($channels as $channel) {
            foreach ($templates as $template => $config) {
                $scores = [];
                foreach ($push[$channel] ?? [] as $key => $score) {
                    $profile = $profiles[$key] ?? null;
                    if ($profile !== null && $profile['role'] === ItemRole::Main && $profile['tier'] === $config['hero_tier'] && self::priced($profile)) {
                        $scores[$key] = $score;
                    }
                }
                // Numeric item keys are int array keys here.
                uksort($scores, fn ($a, $b): int => [$scores[$b], (string) $a] <=> [$scores[$a], (string) $b]);
                $heroes[$channel][$template] = array_slice($scores, 0, $perTemplate, true);
            }
        }
        foreach ($templates as $config) {
            foreach ($config['slots'] as $slot) {
                foreach ($slot['roles'] as $role) {
                    $partnerRoles[$role] = true;
                }
            }
        }

        $partners = array_filter($profiles, fn (array $p): bool => $p['role'] !== null && isset($partnerRoles[$p['role']->value]) && self::priced($p));
        $combos = array_filter($profiles, fn (array $p): bool => $p['is_existing_combo']);
        $heroKeys = [];
        foreach ($heroes as $byTemplate) {
            foreach ($byTemplate as $scores) {
                $heroKeys += $scores;
            }
        }
        $foodSets = $this->foodSets($orgId, array_values(array_unique(array_map('strval', [...array_keys($heroKeys), ...array_keys($partners), ...array_keys($combos)]))));
        $comboSets = array_intersect_key($foodSets, $combos);

        $decisions = $this->ownerDecisions($orgId);
        $perHero = (int) config('menu.intelligence.combos_per_hero');
        $minSupport = (int) config('menu.intelligence.min_support_orders');

        $rows = [];
        foreach ($channels as $channel) {
            $seen = [];
            foreach ($templates as $template => $config) {
                if (ComboTemplate::tryFrom((string) $template) === null) {
                    continue;
                }

                foreach ($heroes[$channel][$template] as $heroKey => $heroScore) {
                    $heroKey = (string) $heroKey;
                    $hero = $profiles[$heroKey];
                    $heroSets = $itemsets[$channel][$heroKey] ?? [];
                    $floor = $floors[ItemRole::Main->value.':'.$hero['tier']] ?? 0.0;

                    foreach ($this->variants($hero, $config['slots'], $partners, $heroSets, $orders[$channel] ?? [], $perHero) as $variant) {
                        $row = $this->row((string) $template, $channel, $hero, $heroScore, $variant, $profiles, $floor, $heroSets, $minSupport, $foodSets, $comboSets);
                        if ($row === null || isset($seen[$row['signature']])) {
                            continue;
                        }
                        $seen[$row['signature']] = true;

                        $decision = $decisions[$row['signature']] ?? null;
                        $row['status'] = $decision['status'] ?? self::STATUS_CANDIDATE;
                        $row['owner_note'] = $decision['owner_note'] ?? null;
                        $row['build_id'] = $buildId;
                        $rows[] = $row;
                    }
                }
            }
        }

        return IntelligenceWriter::replace('combo_candidates', $orgId, $periodKey, $rows);
    }

    /**
     * Up to $perHero member lists for one hero: the n-th best partner of the first non-drink slot,
     * every other slot on its best partner.
     *
     * @param  Profile  $hero
     * @param  list<Slot>  $slots
     * @param  array<string, Profile>  $partners
     * @param  list<Itemset>  $heroSets
     * @param  array<string, int>  $orders  item key => channel orders
     * @return list<array{members: list<array{key: string, qty: int}>, rejected: list<string>}>
     */
    private function variants(array $hero, array $slots, array $partners, array $heroSets, array $orders, int $perHero): array
    {
        $confidence = self::pairConfidence($hero['key'], $heroSets);

        $ranked = [];
        $rejected = [];
        $variantSlot = null;
        foreach ($slots as $i => $slot) {
            $ranked[$i] = $this->rankPartners($hero, $slot, $partners, $confidence, $orders);
            $rejected = [...$rejected, ...$ranked[$i]['rejected']];
            if ($variantSlot === null && ! in_array(ItemRole::Drink->value, $slot['roles'], true)) {
                $variantSlot = $i;
            }
        }
        $rejected = array_values(array_unique($rejected));
        sort($rejected, SORT_STRING);

        $variants = [];
        for ($v = 0; $v < $perHero; $v++) {
            $used = [$hero['key'] => true];
            $members = [['key' => $hero['key'], 'qty' => 1]];

            foreach ($slots as $i => $slot) {
                $skip = $i === $variantSlot ? $v : 0;
                $pick = null;
                foreach ($ranked[$i]['allowed'] as $key) {
                    if (isset($used[$key])) {
                        continue;
                    }
                    if ($skip-- > 0) {
                        continue;
                    }
                    $pick = $key;
                    break;
                }

                if ($pick === null) {
                    // No n-th partner left for the varied slot: every further variant is empty too.
                    if ($i === $variantSlot && $v > 0) {
                        break 2;
                    }
                    if (! ($slot['optional'] ?? false)) {
                        continue 2;
                    }

                    continue;
                }

                $used[$pick] = true;
                $members[] = ['key' => $pick, 'qty' => $slot['qty'] === 'serves_n' ? $hero['serves_n'] : max(1, (int) $slot['qty'])];
            }

            $variants[] = ['members' => $members, 'rejected' => $rejected];
            if ($variantSlot === null) {
                break;
            }
        }

        return $variants;
    }

    /**
     * The slot's partners, best first: preferred classes, then pair confidence with the hero, then
     * the partner's own channel orders. Partners never seen in the channel are left out.
     *
     * @param  Profile  $hero
     * @param  Slot  $slot
     * @param  array<string, Profile>  $partners
     * @param  array<string, float>  $confidence  partner key => together / hero orders
     * @param  array<string, int>  $orders
     * @return Ranked
     */
    private function rankPartners(array $hero, array $slot, array $partners, array $confidence, array $orders): array
    {
        $prefer = $slot['prefer_classes'] ?? [];
        $candidates = [];
        foreach ($partners as $key => $partner) {
            $key = (string) $key;
            if ($key === $hero['key'] || $partner['role'] === null || ! in_array($partner['role']->value, $slot['roles'], true)) {
                continue;
            }
            $pair = $confidence[$key] ?? null;
            $sold = $orders[$key] ?? 0;
            if ($pair === null && $sold <= 0) {
                continue;
            }
            $candidates[] = [
                'key' => $key,
                'preferred' => $partner['top_class'] !== null && in_array($partner['top_class'], $prefer, true),
                'paired' => $pair !== null,
                'confidence' => $pair ?? 0.0,
                'orders' => $sold,
            ];
        }

        usort($candidates, fn (array $a, array $b): int => [$b['preferred'], $b['paired'], $b['confidence'], $b['orders'], $a['key']]
            <=> [$a['preferred'], $a['paired'], $a['confidence'], $a['orders'], $b['key']]);

        $ranked = ['allowed' => [], 'rejected' => []];
        foreach ($candidates as $candidate) {
            $ranked[self::isRedundant($hero, $partners[$candidate['key']]) ? 'rejected' : 'allowed'][] = $candidate['key'];
        }

        return $ranked;
    }

    /**
     * A partner whose largest food class the hero already contains. Drinks, partners without a recipe
     * and partners whose largest class is unclassified (`other`) are never redundant.
     *
     * @param  Profile  $hero
     * @param  Profile  $partner
     */
    private static function isRedundant(array $hero, array $partner): bool
    {
        $class = $partner['top_class'];
        if ($partner['role'] === ItemRole::Drink || ! $partner['has_recipe'] || $class === null
            || $class === FoodClass::Other->value || $class === FoodClass::Drink->value) {
            return false;
        }

        return ($hero['contains'][$class] ?? 0.0) > 0;
    }

    /**
     * One candidate row (without status, owner_note and build_id), or null when no discount keeps the floor.
     *
     * @param  Profile  $hero
     * @param  array{members: list<array{key: string, qty: int}>, rejected: list<string>}  $variant
     * @param  array<string, Profile>  $profiles
     * @param  list<Itemset>  $heroSets
     * @param  array<string, list<string>>  $foodSets
     * @param  array<string, list<string>>  $comboSets
     * @return array<string, mixed>|null
     */
    private function row(string $template, string $channel, array $hero, float $heroScore, array $variant, array $profiles, float $floor, array $heroSets, int $minSupport, array $foodSets, array $comboSets): ?array
    {
        $items = [];
        $listTotal = 0.0;
        $costTotal = 0.0;
        $drinkQty = 0;
        $unknown = [];
        $parts = [];
        $keys = [];
        $food = [];
        foreach ($variant['members'] as $member) {
            $profile = $profiles[$member['key']];
            $role = $profile['key'] === $hero['key'] ? ItemRole::Main : $profile['role'];
            $items[] = ['key' => $profile['key'], 'name' => $profile['name'], 'role' => $role?->value, 'qty' => $member['qty'], 'list_price' => $profile['list_price']];
            $listTotal += $profile['list_price'] * $member['qty'];
            $costTotal += $profile['unit_cost'] * $member['qty'];
            $parts[] = $profile['key'].':'.$member['qty'];
            $keys[$profile['key']] = true;
            foreach ($foodSets[$profile['key']] ?? [] as $component) {
                $food[$component] = true;
            }
            if ($role === ItemRole::Drink) {
                $drinkQty += $member['qty'];
            } elseif (! $profile['has_recipe']) {
                $unknown[] = $profile['key'];
            }
        }

        $pricing = BundlePricer::price($listTotal, $costTotal, $floor);
        if ($pricing === null) {
            return null;
        }

        sort($parts, SORT_STRING);
        sort($unknown, SORT_STRING);
        $overlap = self::overlappingCombo(array_keys($food), $comboSets);
        $evidence = self::evidence($hero['key'], $keys, $heroSets, $minSupport);
        $strength = $evidence['type'] === 'observed' ? min(1.0, $evidence['lift'] / self::FULL_LIFT) : self::RULE_STRENGTH;

        $score = 0.5 * $heroScore + 0.3 * $strength + 0.2 * min(1.0, $pricing['margin_pct'] / self::FULL_MARGIN);
        if ($overlap !== null) {
            $score /= 2;
        }

        return [
            'template' => $template,
            'channel' => $channel,
            'hero_key' => $hero['key'],
            'signature' => sha1($template.'|'.$channel.'|'.implode('|', $parts)),
            'items' => $items,
            'list_total' => round($listTotal, 2),
            'cost_total' => round($costTotal, 2),
            'suggested_price' => $pricing['suggested_price'],
            'margin_pct' => round($pricing['margin_pct'], 4),
            'discount_pct' => round($pricing['discount_pct'], 4),
            'evidence' => $evidence,
            'checks' => [
                'redundant_rejected' => $variant['rejected'],
                'unknown_recipe' => $unknown,
                'drinks_match_serves' => $drinkQty === $hero['serves_n'],
                'floor' => round($floor, 4),
            ],
            'overlaps_combo_key' => $overlap,
            'score' => round($score, 4),
        ];
    }

    /**
     * The largest observed itemset holding the hero and only candidate items, with enough orders
     * to trust its lift; otherwise a rule-based candidate.
     *
     * @param  array<string, true>  $keys  candidate item keys
     * @param  list<Itemset>  $heroSets
     * @return array{type: 'observed', itemset: list<string>, together_orders: int, support: float, lift: float, why: string}|array{type: 'rule', why: string}
     */
    private static function evidence(string $heroKey, array $keys, array $heroSets, int $minSupport): array
    {
        $best = null;
        foreach ($heroSets as $set) {
            if ($set['together'] < $minSupport || ! in_array($heroKey, $set['items'], true)) {
                continue;
            }
            foreach ($set['items'] as $item) {
                if (! isset($keys[$item])) {
                    continue 2;
                }
            }
            if ($best === null || [$set['size'], $set['lift'], $set['together']] > [$best['size'], $best['lift'], $best['together']]) {
                $best = $set;
            }
        }

        if ($best === null) {
            return ['type' => 'rule', 'why' => 'مفيش طلبات كفاية اتطلبت فيها الأصناف دي مع بعض، فالتشكيلة مبنية على قواعد القالب، والسعر بيحافظ على هامش الربح المطلوب.'];
        }

        return [
            'type' => 'observed',
            'itemset' => $best['items'],
            'together_orders' => $best['together'],
            'support' => $best['support'],
            'lift' => $best['lift'],
            'why' => 'الأصناف دي اتطلبت مع بعض في '.$best['together'].' طلب، وقوة الارتباط '.round($best['lift'], 2).'، والسعر بيحافظ على هامش الربح المطلوب.',
        ];
    }

    /**
     * The existing combo whose food components match the candidate's best, at or above the threshold.
     *
     * @param  list<string>  $food  candidate food component keys
     * @param  array<string, list<string>>  $comboSets
     */
    private static function overlappingCombo(array $food, array $comboSets): ?string
    {
        if ($food === []) {
            return null;
        }

        $threshold = (float) config('menu.intelligence.combo_overlap_threshold');
        $candidate = array_flip($food);
        $best = null;
        foreach ($comboSets as $key => $set) {
            if ($set === []) {
                continue;
            }
            $combo = array_flip($set);
            $jaccard = count(array_intersect_key($candidate, $combo)) / count($candidate + $combo);
            if ($jaccard >= $threshold && ($best === null || $jaccard > $best[1])) {
                $best = [(string) $key, $jaccard];
            }
        }

        return $best[0] ?? null;
    }

    /**
     * Partner key => pair confidence (together / hero orders) from the hero's 2-itemsets.
     *
     * @param  list<Itemset>  $heroSets
     * @return array<string, float>
     */
    private static function pairConfidence(string $heroKey, array $heroSets): array
    {
        $confidence = [];
        foreach ($heroSets as $set) {
            $heroOrders = $set['member_orders'][$heroKey] ?? 0;
            if ($set['size'] !== 2 || $heroOrders <= 0) {
                continue;
            }
            foreach ($set['items'] as $item) {
                if ($item !== $heroKey) {
                    $confidence[$item] = $set['together'] / $heroOrders;
                }
            }
        }

        return $confidence;
    }

    /** @param  Profile  $profile */
    private static function priced(array $profile): bool
    {
        return $profile['list_price'] > 0 && $profile['unit_cost'] > 0;
    }

    /**
     * @return array<string, Profile>
     */
    private function profiles(int $orgId): array
    {
        $profiles = [];
        $rows = ItemProfile::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->get(['item_key', 'name', 'role', 'price_tier', 'serves_n', 'list_price', 'unit_cost', 'contains', 'has_recipe', 'is_existing_combo']);

        foreach ($rows as $row) {
            $key = (string) $row->item_key;
            $contains = [];
            foreach ((array) $row->contains as $class => $qty) {
                $contains[(string) $class] = (float) $qty;
            }

            $profiles[$key] = [
                'key' => $key,
                'name' => $row->name,
                'role' => $row->role !== null ? ItemRole::tryFrom($row->role) : null,
                'tier' => $row->price_tier,
                'serves_n' => max(1, $row->serves_n),
                'list_price' => $row->list_price,
                'unit_cost' => $row->unit_cost,
                'contains' => $contains,
                'top_class' => self::topClass($contains),
                'has_recipe' => $row->has_recipe,
                'is_existing_combo' => $row->is_existing_combo,
            ];
        }

        return $profiles;
    }

    /**
     * The food class with the largest quantity (ties: alphabetical), or null when nothing is known.
     *
     * @param  array<string, float>  $contains
     */
    private static function topClass(array $contains): ?string
    {
        $contains = array_filter($contains, fn (float $qty): bool => $qty > 0);
        if ($contains === []) {
            return null;
        }
        uksort($contains, fn (string $a, string $b): int => [$contains[$b], $a] <=> [$contains[$a], $b]);

        return (string) array_key_first($contains);
    }

    /**
     * Each item's food component keys, without components whose trusted class is unknown (`other`).
     *
     * @param  list<string>  $itemKeys
     * @return array<string, list<string>>
     */
    private function foodSets(int $orgId, array $itemKeys): array
    {
        $known = [];
        $rows = ComponentClass::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->get(['component_key', 'class', 'source', 'confidence']);
        foreach ($rows as $row) {
            $class = FoodClass::tryFrom($row->class);
            if ($class !== null && $class !== FoodClass::Other && $class->isFood() && LabelTrust::isTrusted($row->source, (float) $row->confidence)) {
                $known[(string) $row->component_key] = true;
            }
        }

        $sets = [];
        // One call for every key: each call reloads the org's component classes.
        foreach ($this->profileBuilder->foodComponentKeys($orgId, $itemKeys) as $itemKey => $components) {
            $sets[(string) $itemKey] = array_values(array_filter($components, fn (string $component): bool => isset($known[$component])));
        }

        return $sets;
    }

    /**
     * @return array<string, array<string, float>> channel => item key => push_next_month score
     */
    private function pushScores(int $orgId, string $periodKey): array
    {
        $rows = DB::table('item_rankings')
            ->where('organization_id', $orgId)
            ->where('period_key', $periodKey)
            ->where('strategy', ItemRanker::PUSH_NEXT_MONTH)
            ->get(['channel', 'item_key', 'score']);

        $scores = [];
        foreach ($rows as $row) {
            $scores[(string) $row->channel][(string) $row->item_key] = (float) $row->score;
        }

        return $scores;
    }

    /**
     * @return array<string, array<string, int>> channel => item key => orders (all branches)
     */
    private function orders(int $orgId, string $periodKey): array
    {
        $rows = DB::table('item_period_stats')
            ->where('organization_id', $orgId)
            ->where('period_key', $periodKey)
            ->where('branch_id', 0)
            ->get(['channel', 'item_key', 'orders']);

        $orders = [];
        foreach ($rows as $row) {
            $orders[(string) $row->channel][(string) $row->item_key] = (int) $row->orders;
        }

        return $orders;
    }

    /**
     * Margin floor per "<role>:<price_tier>" segment: Σ(net − cogs) / Σ net over the segment's items
     * eligible for ranking (channel all, branch 0), never below 0.
     *
     * @param  array<string, Profile>  $profiles
     * @return array<string, float>
     */
    private function segmentFloors(int $orgId, string $periodKey, array $profiles): array
    {
        $rows = DB::table('item_period_stats')
            ->where('organization_id', $orgId)
            ->where('period_key', $periodKey)
            ->where('branch_id', 0)
            ->where('channel', self::CHANNEL_ALL)
            ->get(['item_key', 'qty', 'net_sales', 'cogs', 'days_sold']);

        $minDays = (int) config('menu.intelligence.min_days_on_sale');
        $net = [];
        $profit = [];
        foreach ($rows as $row) {
            $profile = $profiles[(string) $row->item_key] ?? null;
            $cogs = (float) $row->cogs;
            if ($profile === null || $profile['role'] === null || ! $profile['role']->isMarketable()
                || (int) $row->days_sold < $minDays || $cogs <= 0.0 || (float) $row->qty <= 0.0) {
                continue;
            }

            $segment = $profile['role']->value.':'.$profile['tier'];
            $net[$segment] = ($net[$segment] ?? 0.0) + (float) $row->net_sales;
            $profit[$segment] = ($profit[$segment] ?? 0.0) + (float) $row->net_sales - $cogs;
        }

        // A loss-making segment never lowers the bar below break-even: the combo must still earn a margin.
        $floors = [];
        foreach ($net as $segment => $sum) {
            $floors[$segment] = $sum > 0 ? max(0.0, $profit[$segment] / $sum) : 0.0;
        }

        return $floors;
    }

    /**
     * The period's itemsets per channel, indexed under each member key.
     *
     * @return array<string, array<string, list<Itemset>>> channel => item key => itemsets holding it
     */
    private function itemsets(int $orgId, string $periodKey): array
    {
        $rows = ItemAffinity::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('period_key', $periodKey)
            ->get(['channel', 'size', 'items', 'member_orders', 'together_orders', 'support', 'lift']);

        $itemsets = [];
        foreach ($rows as $row) {
            // Numeric item keys decode as ints, both in the list and as member_orders keys.
            $items = array_map(fn ($key): string => (string) $key, $row->items);
            $members = [];
            foreach ($row->member_orders as $key => $count) {
                $members[(string) $key] = (int) $count;
            }

            $set = [
                'items' => $items,
                'size' => $row->size,
                'member_orders' => $members,
                'together' => $row->together_orders,
                'support' => $row->support,
                'lift' => $row->lift,
            ];
            foreach ($items as $key) {
                $itemsets[$row->channel][$key][] = $set;
            }
        }

        return $itemsets;
    }

    /**
     * Owner decisions (approved / rejected) of any period, by signature; the latest one wins.
     *
     * @return array<string, array{status: string, owner_note: string|null}>
     */
    private function ownerDecisions(int $orgId): array
    {
        $rows = ComboCandidate::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->where('status', '!=', self::STATUS_CANDIDATE)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get(['signature', 'status', 'owner_note']);

        $decisions = [];
        foreach ($rows as $row) {
            $decisions[$row->signature] = ['status' => $row->status, 'owner_note' => $row->owner_note];
        }

        return $decisions;
    }
}
