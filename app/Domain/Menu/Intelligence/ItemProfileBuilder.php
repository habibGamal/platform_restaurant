<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

use App\Domain\Menu\ItemKey;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Models\Menu\CategoryProfile;
use App\Models\Menu\ComponentClass;
use App\Models\Menu\ItemProfile;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * Builds the org's "Item Cards": what each item is (role, category, season), who it serves, what it
 * contains and what it costs, from the warehouse, the recipes and the trusted classifier labels.
 *
 * Runs in queue jobs without a user: every query is org-filtered by hand.
 */
final class ItemProfileBuilder
{
    /** Items sold within this many days are profiled even without a recipe. */
    private const SOLD_DAYS = 180;

    /** Window for the sales-based unit cost (same span as rolling_90d). */
    private const COST_DAYS = 90;

    public const MAX_SERVES = 12;

    public function __construct(private readonly ItemRoles $roles) {}

    /** Number of profiles written; 0 (and nothing written) when the org has no items. */
    public function build(Organization $org, CarbonImmutable $today): int
    {
        $orgId = $org->id;
        $recipes = $this->chosenRecipes($orgId);
        $products = $this->products($orgId);

        $keys = $this->soldKeys($orgId, $today);
        foreach ($recipes as $key => $recipe) {
            if ($recipe['manufactured']) {
                $keys[$key] = true;
            }
        }

        if ($keys === []) {
            // An empty write would delete every profile, owner overrides included.
            Log::warning('Item profile build found no items; nothing written.', ['organization_id' => $orgId]);

            return 0;
        }

        $roles = $this->roles->mapFor($orgId);
        $classes = $this->componentClasses($orgId);
        $categories = $this->categoryProfiles($orgId);
        $ownerServes = $this->ownerServes($orgId);
        $salesCost = $this->salesUnitCosts($orgId, $today);

        $items = [];
        foreach (array_keys($keys) as $key) {
            $key = (string) $key;
            $product = $products[$key] ?? null;
            $lines = $recipes[$key]['lines'] ?? [];

            $contains = [];
            $cutlery = [];
            foreach ($lines as $line) {
                $class = $classes[$line['key']] ?? FoodClass::Other;
                if ($class === FoodClass::Cutlery) {
                    $cutlery[$line['key']] = ($cutlery[$line['key']] ?? 0.0) + $line['qty'];
                }
                if ($class->isFood()) {
                    $contains[$class->value] = ($contains[$class->value] ?? 0.0) + $line['qty'];
                }
            }
            $contains = array_map(fn (float $qty): float => round($qty, 3), $contains);

            $categoryKey = $product['category_key'] ?? null;
            $category = $categoryKey !== null ? ($categories[$categoryKey] ?? null) : null;
            [$servesN, $servesSource] = $this->serves($ownerServes[$key] ?? null, $cutlery, $category);

            $items[$key] = [
                'key' => $key,
                'name' => $product['name'] ?? $key,
                'category_key' => $categoryKey,
                'role' => $roles->roleOf($key),
                'serves_n' => $servesN,
                'serves_source' => $servesSource,
                'season' => $category['season'] ?? Season::All->value,
                'list_price' => round($product['avg_price'] ?? 0.0, 2),
                'unit_cost' => round($salesCost[$key] ?? $product['avg_cost'] ?? 0.0, 2),
                'contains' => $contains,
                'food_keys' => self::foodKeys($lines, $classes),
                'has_recipe' => $lines !== [],
            ];
        }

        $medians = $this->roleMedians($items);

        $rows = [];
        foreach ($items as $item) {
            $isCombo = $item['role'] === ItemRole::Combo;

            $rows[] = [
                'item_key' => $item['key'],
                'name' => $item['name'],
                'category_key' => $item['category_key'],
                'role' => $item['role']?->value,
                'serves_n' => $item['serves_n'],
                'serves_source' => $item['serves_source'],
                'price_tier' => $this->priceTier($item, $medians)->value,
                'price_tier_source' => 'auto',
                'season' => $item['season'],
                'season_source' => 'auto',
                'list_price' => $item['list_price'],
                'unit_cost' => $item['unit_cost'],
                // Pre-encoded so an empty map is stored as {} rather than [].
                'contains' => json_encode($item['contains'] === [] ? new stdClass : $item['contains'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'main_protein' => $this->mainProtein($item['contains']),
                'has_recipe' => $item['has_recipe'],
                'is_existing_combo' => $isCombo,
                'combo_members' => $isCombo ? $this->comboMembers($item['food_keys'], $items) : null,
                'built_at' => now(),
            ];
        }

        return IntelligenceWriter::replaceProfiles($orgId, $rows);
    }

    /**
     * The food-class component keys of each item's chosen recipe (sorted; empty without a recipe).
     *
     * @param  list<string>  $itemKeys
     * @return array<string, list<string>>
     */
    public function foodComponentKeys(int $organizationId, array $itemKeys): array
    {
        if ($itemKeys === []) {
            return [];
        }

        $recipes = $this->chosenRecipes($organizationId, $itemKeys);
        $classes = $this->componentClasses($organizationId);

        $keys = [];
        foreach ($itemKeys as $itemKey) {
            $keys[$itemKey] = self::foodKeys($recipes[$itemKey]['lines'] ?? [], $classes);
        }

        return $keys;
    }

    /**
     * Sorted distinct component keys of the recipe lines whose (trusted) class is food.
     *
     * @param  list<array{key: string, qty: float}>  $lines
     * @param  array<string, FoodClass>  $classes
     * @return list<string>
     */
    private static function foodKeys(array $lines, array $classes): array
    {
        $food = [];
        foreach ($lines as $line) {
            if (($classes[$line['key']] ?? FoodClass::Other)->isFood()) {
                $food[$line['key']] = $line['key'];
            }
        }
        $food = array_values($food);
        sort($food, SORT_STRING);

        return $food;
    }

    /**
     * One recipe per item key. dim_products rows exist per branch, so the product row with the most
     * recipe lines wins (tie: highest recipe source_version, then lowest product id). Components are
     * keyed like items, from the component's dim_products row on the same branch (its uuid when missing).
     *
     * @param  list<string>|null  $itemKeys  null = every item of the org
     * @return array<string, array{manufactured: bool, lines: list<array{key: string, qty: float}>}> manufactured = any branch row is
     */
    private function chosenRecipes(int $organizationId, ?array $itemKeys = null): array
    {
        $parentKey = 'COALESCE(p.product_ref, p.uuid)';
        $query = DB::table('dim_recipes as r')
            ->join('dim_products as p', function ($join) use ($organizationId): void {
                $join->on('p.branch_id', '=', 'r.branch_id')->on('p.uuid', '=', 'r.product_uuid')
                    ->where('p.organization_id', $organizationId)->whereNull('p.deleted_at');
            })
            ->leftJoin('dim_products as c', function ($join) use ($organizationId): void {
                $join->on('c.branch_id', '=', 'r.branch_id')->on('c.uuid', '=', 'r.component_uuid')
                    ->where('c.organization_id', $organizationId);
            })
            ->where('r.organization_id', $organizationId)
            ->selectRaw("{$parentKey} as item_key, p.id as product_id, p.type, r.source_version, r.quantity,
                COALESCE(c.product_ref, c.uuid, r.component_uuid) as component_key")
            ->orderBy('r.id');

        if ($itemKeys !== null) {
            $query->whereIn(DB::raw($parentKey), $itemKeys);
        }

        /** @var array<string, array<int, array{manufactured: bool, version: int, lines: list<array{key: string, qty: float}>}>> $candidates */
        $candidates = [];
        foreach ($query->get() as $row) {
            $itemKey = (string) $row->item_key;
            $productId = (int) $row->product_id;
            $candidates[$itemKey][$productId] ??= ['manufactured' => $row->type === 'manufactured', 'version' => 0, 'lines' => []];
            $candidates[$itemKey][$productId]['version'] = max($candidates[$itemKey][$productId]['version'], (int) $row->source_version);
            $candidates[$itemKey][$productId]['lines'][] = ['key' => (string) $row->component_key, 'qty' => (float) $row->quantity];
        }

        $chosen = [];
        foreach ($candidates as $itemKey => $byProduct) {
            $best = null;
            $manufactured = false;
            // Product ids ascend, so a later candidate wins only when strictly better.
            ksort($byProduct);
            foreach ($byProduct as $candidate) {
                $manufactured = $manufactured || $candidate['manufactured'];
                if ($best === null || [count($candidate['lines']), $candidate['version']] > [count($best['lines']), $best['version']]) {
                    $best = $candidate;
                }
            }
            if ($best !== null) {
                // Any manufactured branch row with a recipe puts the item on the profile list.
                $chosen[$itemKey] = ['manufactured' => $manufactured, 'lines' => $best['lines']];
            }
        }

        return $chosen;
    }

    /**
     * Name and category from the first product row in id order; price and cost averaged over the
     * non-zero values of the key's non-deleted branch rows.
     *
     * @return array<string, array{name: string, category_key: string|null, avg_price: float|null, avg_cost: float|null}>
     */
    private function products(int $organizationId): array
    {
        $rows = DB::table('dim_products as p')
            ->leftJoin('dim_categories as cat', function ($join): void {
                $join->on('cat.branch_id', '=', 'p.branch_id')->on('cat.uuid', '=', 'p.category_uuid');
            })
            ->where('p.organization_id', $organizationId)
            ->whereNull('p.deleted_at')
            ->orderBy('p.id')
            ->selectRaw('COALESCE(p.product_ref, p.uuid) as item_key, p.name, p.price, p.cost, cat.name as category_name')
            ->get();

        $first = [];
        $prices = [];
        $costs = [];
        foreach ($rows as $row) {
            $key = (string) $row->item_key;
            $first[$key] ??= $row;
            if ((float) $row->price > 0) {
                $prices[$key][] = (float) $row->price;
            }
            if ((float) $row->cost > 0) {
                $costs[$key][] = (float) $row->cost;
            }
        }

        $products = [];
        foreach ($first as $key => $row) {
            $products[$key] = [
                'name' => (string) $row->name,
                'category_key' => $row->category_name !== null ? ItemKey::normalizeCategory((string) $row->category_name) : null,
                'avg_price' => isset($prices[$key]) ? array_sum($prices[$key]) / count($prices[$key]) : null,
                'avg_cost' => isset($costs[$key]) ? array_sum($costs[$key]) / count($costs[$key]) : null,
            ];
        }

        return $products;
    }

    /** @return array<string, true> */
    private function soldKeys(int $organizationId, CarbonImmutable $today): array
    {
        $keys = DB::table('agg_product_daily')
            ->where('organization_id', $organizationId)
            ->whereBetween('business_date', [$today->subDays(self::SOLD_DAYS)->toDateString(), $today->subDay()->toDateString()])
            ->where('qty_sold', '>', 0)
            ->distinct()
            ->pluck(DB::raw(ItemKey::SQL.' as item_key'));

        $sold = [];
        foreach ($keys as $key) {
            $sold[(string) $key] = true;
        }

        return $sold;
    }

    /**
     * Σcogs / Σqty over the last 90 days, for items where both are positive.
     *
     * @return array<string, float>
     */
    private function salesUnitCosts(int $organizationId, CarbonImmutable $today): array
    {
        $rows = DB::table('agg_product_daily')
            ->where('organization_id', $organizationId)
            ->whereBetween('business_date', [$today->subDays(self::COST_DAYS)->toDateString(), $today->subDay()->toDateString()])
            ->selectRaw(ItemKey::SQL.' as item_key, SUM(qty_sold) as qty, SUM(cogs) as cogs')
            ->groupBy(DB::raw(ItemKey::SQL))
            ->get();

        $costs = [];
        foreach ($rows as $row) {
            if ((float) $row->qty > 0 && (float) $row->cogs > 0) {
                $costs[(string) $row->item_key] = (float) $row->cogs / (float) $row->qty;
            }
        }

        return $costs;
    }

    /**
     * Trusted component classes; anything else counts as `other`.
     *
     * @return array<string, FoodClass>
     */
    private function componentClasses(int $organizationId): array
    {
        $classes = [];
        $rows = ComponentClass::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->get(['component_key', 'class', 'source', 'confidence']);
        foreach ($rows as $row) {
            $class = FoodClass::tryFrom($row->class);
            if ($class !== null && LabelTrust::isTrusted($row->source, (float) $row->confidence)) {
                $classes[$row->component_key] = $class;
            }
        }

        return $classes;
    }

    /**
     * Trusted category profiles only.
     *
     * @return array<string, array{season: string, meal_type: string}>
     */
    private function categoryProfiles(int $organizationId): array
    {
        $profiles = [];
        $rows = CategoryProfile::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->get(['category_key', 'season', 'meal_type', 'source', 'confidence']);
        foreach ($rows as $row) {
            if (LabelTrust::isTrusted($row->source, (float) $row->confidence)) {
                $profiles[$row->category_key] = ['season' => $row->season, 'meal_type' => $row->meal_type];
            }
        }

        return $profiles;
    }

    /**
     * Owner-set serves_n, so the price tier follows the owner's answer (the writer keeps the value itself).
     *
     * @return array<string, int>
     */
    private function ownerServes(int $organizationId): array
    {
        return ItemProfile::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('serves_source', 'owner')
            ->pluck('serves_n', 'item_key')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /**
     * @param  array<string, float>  $cutlery  cutlery component key => summed recipe qty
     * @param  array{season: string, meal_type: string}|null  $category  trusted category profile
     * @return array{0: int, 1: string} [serves_n, serves_source]
     */
    private function serves(?int $owner, array $cutlery, ?array $category): array
    {
        if ($owner !== null) {
            return [$owner, 'owner'];
        }

        // A tray packed with 4 forks serves 4.
        if ($cutlery !== []) {
            return [max(1, min(self::MAX_SERVES, (int) round(max($cutlery)))), 'recipe'];
        }

        $byCategory = match ($category['meal_type'] ?? null) {
            MealType::Family->value => 4,
            MealType::Sharing->value => 2,
            default => null,
        };

        return $byCategory !== null ? [$byCategory, 'category'] : [1, 'default'];
    }

    /**
     * @param  array{role: ItemRole|null, serves_n: int, serves_source: string, list_price: float}  $item
     * @param  array<string, float>  $medians
     */
    private function priceTier(array $item, array $medians): PriceTier
    {
        // Without any serving signal, a price far above its role's median is the best hint left.
        $median = $item['role'] !== null ? ($medians[$item['role']->value] ?? 0.0) : 0.0;
        if ($item['serves_source'] === 'default' && $median > 0) {
            if ($item['list_price'] >= 4 * $median) {
                return PriceTier::Family;
            }
            if ($item['list_price'] >= 2 * $median) {
                return PriceTier::Sharing;
            }
        }

        return PriceTier::forServes($item['serves_n']);
    }

    /**
     * Median non-zero list price per trusted role.
     *
     * @param  array<string, array{role: ItemRole|null, list_price: float}>  $items
     * @return array<string, float>
     */
    private function roleMedians(array $items): array
    {
        $prices = [];
        foreach ($items as $item) {
            if ($item['role'] !== null && $item['list_price'] > 0) {
                $prices[$item['role']->value][] = $item['list_price'];
            }
        }

        $medians = [];
        foreach ($prices as $role => $values) {
            sort($values);
            $n = count($values);
            $mid = intdiv($n, 2);
            $medians[$role] = $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
        }

        return $medians;
    }

    /** @param  array<string, float>  $contains */
    private function mainProtein(array $contains): ?string
    {
        $best = null;
        foreach (FoodClass::cases() as $class) {
            $qty = $contains[$class->value] ?? 0.0;
            if ($class->isProtein() && $qty > 0 && ($best === null || $qty > $best[1])) {
                $best = [$class->value, $qty];
            }
        }

        return $best[0] ?? null;
    }

    /**
     * Non-combo items with a recipe whose food components are mostly inside the combo's.
     *
     * @param  list<string>  $comboKeys
     * @param  array<string, array{key: string, role: ItemRole|null, food_keys: list<string>}>  $items
     * @return list<string>
     */
    private function comboMembers(array $comboKeys, array $items): array
    {
        if ($comboKeys === []) {
            return [];
        }

        $threshold = (float) config('menu.intelligence.combo_overlap_threshold');
        $combo = array_flip($comboKeys);
        $members = [];
        foreach ($items as $item) {
            if ($item['role'] === ItemRole::Combo || $item['food_keys'] === []) {
                continue;
            }

            $covered = count(array_intersect_key(array_flip($item['food_keys']), $combo));
            if ($covered / count($item['food_keys']) >= $threshold) {
                $members[] = $item['key'];
            }
        }
        sort($members, SORT_STRING);

        return $members;
    }
}
