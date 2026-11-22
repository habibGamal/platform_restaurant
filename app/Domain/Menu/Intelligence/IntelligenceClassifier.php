<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

use App\Ai\Agents\ComponentClassifier;
use App\Domain\AiBilling\BudgetGuard;
use App\Domain\AiBilling\UsageRecorder;
use App\Domain\Assistant\AiAccess;
use App\Domain\Menu\ItemKey;
use App\Models\Menu\CategoryProfile;
use App\Models\Menu\ComponentClass;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Labels the org's recipe components with a FoodClass and its menu categories with a season and a
 * meal type, so item profiles can be built from data instead of guessed at question time.
 *
 * Cutlery and packaging are caught by name rules first (no model call); the rest goes to the model in
 * batches. Owner rows are never written over or sent to the model, and labels judged recently are not
 * asked about again, whatever their confidence (as RoleClassifier does), so a nightly run does not pay
 * for the same doubtful answers every time. Runs in queue jobs: every query is org-filtered by hand.
 */
final class IntelligenceClassifier
{
    public const RULE_CONFIDENCE = 0.95;

    /** Menu products named per component when asking the model. */
    private const SAMPLE_PRODUCTS = 3;

    /** Items listed per category when asking the model. */
    private const SAMPLE_ITEMS = 12;

    /** Name fragments (Arabic, matched anywhere in the name) that mark a cutlery component. */
    private const CUTLERY_AR = ['شوكه', 'شوكة', 'معلقه', 'معلقة', 'سكينه', 'سكينة'];

    /** English cutlery words, matched as whole words so a name like "forktail" does not count. */
    private const CUTLERY_EN = '/\b(?:forks?|spoons?|knife|knives)\b/i';

    private const PACKAGING_AR = [
        'كيس', 'علبه', 'علبة', 'فويل', 'استرتش', 'ورق زبد', 'منديل', 'مناديل', 'شنط', 'ظرف', 'غطا',
        'استيكر', 'ريمكال', 'ريميكال', 'كرتون',
    ];

    /** Whole word, so "baguette" is not packaging. */
    private const PACKAGING_EN = '/\bbags?\b/i';

    /** Model calls the current classify() run may still make; null = no limit. */
    private ?int $modelCallsLeft = null;

    /** Set once the budget runs out (or AI is off): nothing more is sent to the model this run. */
    private bool $stopAsking = false;

    public function __construct(
        private readonly AiAccess $access,
        private readonly BudgetGuard $budget,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * @param  int|null  $maxModelCalls  stop asking the model after this many calls (null = no limit)
     * @return array{components: int, categories: int, deferred: int} rows written per table, and entries
     *                                                                left for a later run (call cap,
     *                                                                spent budget or AI switched off)
     */
    public function classify(Organization $organization, ?int $maxModelCalls = null): array
    {
        $this->modelCallsLeft = $maxModelCalls;
        $this->stopAsking = ! $this->access->allowsOrganization($organization);

        [$components, $deferredComponents] = $this->classifyComponents($organization);
        [$categories, $deferredCategories] = $this->classifyCategories($organization);

        return [
            'components' => $components,
            'categories' => $categories,
            'deferred' => $deferredComponents + $deferredCategories,
        ];
    }

    public function setComponentClass(int $organizationId, string $key, FoodClass $class): void
    {
        $existing = $this->components()->where('organization_id', $organizationId)->where('component_key', $key)->value('name');
        $name = $existing ?? DB::table('dim_products')
            ->where('organization_id', $organizationId)
            ->whereRaw('COALESCE(product_ref, uuid) = ?', [$key])
            ->value('name');

        $this->components()->updateOrCreate(
            ['organization_id' => $organizationId, 'component_key' => $key],
            [
                'name' => is_string($name) ? $name : $key,
                'class' => $class->value,
                'source' => 'owner',
                'confidence' => 1.00,
                'classified_at' => now(),
            ],
        );
    }

    public function setCategoryProfile(int $organizationId, string $key, Season $season, MealType $mealType): void
    {
        $key = ItemKey::normalizeCategory($key);
        $name = $this->categories()->where('organization_id', $organizationId)->where('category_key', $key)->value('name');

        if (! is_string($name)) {
            $name = DB::table('dim_categories')
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('name')
                ->first(fn ($candidate): bool => ItemKey::normalizeCategory((string) $candidate) === $key);
        }

        $this->categories()->updateOrCreate(
            ['organization_id' => $organizationId, 'category_key' => $key],
            [
                'name' => is_string($name) ? $name : $key,
                'season' => $season->value,
                'meal_type' => $mealType->value,
                'source' => 'owner',
                'confidence' => 1.00,
                'classified_at' => now(),
            ],
        );
    }

    /** FoodClass the name rules give a component, or null when no rule matches. */
    public static function ruleClass(string $name): ?FoodClass
    {
        if (self::containsAny($name, self::CUTLERY_AR) || preg_match(self::CUTLERY_EN, $name) === 1) {
            return FoodClass::Cutlery;
        }

        if (self::containsAny($name, self::PACKAGING_AR) || preg_match(self::PACKAGING_EN, $name) === 1) {
            return FoodClass::Packaging;
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int} [rows written, entries deferred]
     */
    private function classifyComponents(Organization $organization): array
    {
        $orgId = $organization->id;
        $settled = $this->settledKeys($this->components(), 'component_key', $orgId);

        $ruled = [];
        $pending = [];
        foreach ($this->componentEntries($orgId) as $key => $entry) {
            if (isset($settled[$key])) {
                continue;
            }

            $class = self::ruleClass($entry['name']);
            if ($class !== null) {
                $ruled[$key] = ['name' => $entry['name'], 'class' => $class->value, 'source' => 'rule', 'confidence' => self::RULE_CONFIDENCE];
            } else {
                $pending[] = $entry;
            }
        }

        $written = $this->persist($this->components(), 'component_key', $orgId, $ruled);

        $names = array_column($pending, 'name', 'key');
        [$asked, $deferred] = $this->askInBatches($organization, ComponentClassifier::KIND_COMPONENT, $pending, function (array $answer) use ($names): ?array {
            $class = is_string($answer['class'] ?? null) ? FoodClass::tryFrom($answer['class']) : null;

            return $class === null ? null : ['name' => $names[$answer['key']], 'class' => $class->value];
        }, fn (array $rows): int => $this->persist($this->components(), 'component_key', $orgId, $rows));

        return [$written + $asked, $deferred];
    }

    /**
     * @return array{0: int, 1: int} [rows written, entries deferred]
     */
    private function classifyCategories(Organization $organization): array
    {
        $orgId = $organization->id;
        $settled = $this->settledKeys($this->categories(), 'category_key', $orgId);

        $pending = array_values(array_diff_key($this->categoryEntries($orgId), $settled));

        $names = array_column($pending, 'name', 'key');

        return $this->askInBatches($organization, ComponentClassifier::KIND_CATEGORY, $pending, function (array $answer) use ($names): ?array {
            $season = is_string($answer['season'] ?? null) ? Season::tryFrom($answer['season']) : null;
            $mealType = is_string($answer['meal_type'] ?? null) ? MealType::tryFrom($answer['meal_type']) : null;

            return $season === null || $mealType === null
                ? null
                : ['name' => $names[$answer['key']], 'season' => $season->value, 'meal_type' => $mealType->value];
        }, fn (array $rows): int => $this->persist($this->categories(), 'category_key', $orgId, $rows));
    }

    /**
     * Every component used in a recipe, keyed like dim_products items, with the menu products using it.
     *
     * @return array<string, array{key: string, name: string, used_in: int, sample_products: list<string>}>
     */
    private function componentEntries(int $organizationId): array
    {
        $componentKey = 'COALESCE(c.product_ref, c.uuid)';
        $parentKey = 'COALESCE(p.product_ref, p.uuid)';

        $base = fn () => DB::table('dim_recipes as r')
            ->join('dim_products as c', function ($join): void {
                $join->on('c.branch_id', '=', 'r.branch_id')->on('c.uuid', '=', 'r.component_uuid');
            })
            ->where('r.organization_id', $organizationId)
            ->where('c.organization_id', $organizationId);

        $components = $base()
            ->leftJoin('dim_products as p', function ($join): void {
                $join->on('p.branch_id', '=', 'r.branch_id')->on('p.uuid', '=', 'r.product_uuid')->whereNull('p.deleted_at');
            })
            ->selectRaw("{$componentKey} as component_key, MIN(c.name) as name, COUNT(DISTINCT {$parentKey}) as used_in")
            ->groupByRaw($componentKey)
            ->orderByRaw($componentKey)
            ->get();

        $samples = [];
        $usages = $base()
            ->join('dim_products as p', function ($join): void {
                $join->on('p.branch_id', '=', 'r.branch_id')->on('p.uuid', '=', 'r.product_uuid')->whereNull('p.deleted_at');
            })
            ->selectRaw("{$componentKey} as component_key, p.name as product_name")
            ->distinct()
            ->orderBy('product_name')
            ->get();
        foreach ($usages as $usage) {
            $key = (string) $usage->component_key;
            $samples[$key] ??= [];
            if (count($samples[$key]) < self::SAMPLE_PRODUCTS) {
                $samples[$key][] = (string) $usage->product_name;
            }
        }

        $entries = [];
        foreach ($components as $row) {
            $key = (string) $row->component_key;
            $entries[$key] = [
                'key' => $key,
                'name' => (string) $row->name,
                'used_in' => (int) $row->used_in,
                'sample_products' => $samples[$key] ?? [],
            ];
        }

        return $entries;
    }

    /**
     * Every category of the org, merged across branches by normalized name, with a few of its items.
     *
     * @return array<string, array{key: string, name: string, sample_items: list<array{name: string, price: float}>}>
     */
    private function categoryEntries(int $organizationId): array
    {
        $entries = [];
        $keyOf = [];
        $rows = DB::table('dim_categories')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['branch_id', 'uuid', 'name']);
        foreach ($rows as $row) {
            $key = ItemKey::normalizeCategory((string) $row->name);
            $keyOf[$row->branch_id.'|'.$row->uuid] = $key;
            $entries[$key] ??= ['key' => $key, 'name' => (string) $row->name, 'sample_items' => []];
        }

        if ($entries === []) {
            return [];
        }

        // One sample per item key; the highest branch price stands for the item.
        $items = [];
        $products = DB::table('dim_products')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereNotNull('category_uuid')
            ->orderBy('name')
            ->get(['branch_id', 'category_uuid', 'product_ref', 'uuid', 'name', 'price']);
        foreach ($products as $product) {
            $categoryKey = $keyOf[$product->branch_id.'|'.$product->category_uuid] ?? null;
            if ($categoryKey === null) {
                continue;
            }

            $itemKey = (string) ($product->product_ref ?? $product->uuid);
            $price = round((float) $product->price, 2);
            $existing = $items[$categoryKey][$itemKey] ?? null;
            if ($existing === null) {
                $items[$categoryKey][$itemKey] = ['name' => (string) $product->name, 'price' => $price];
            } elseif ($price > $existing['price']) {
                $items[$categoryKey][$itemKey]['price'] = $price;
            }
        }

        foreach (array_keys($entries) as $categoryKey) {
            $entries[$categoryKey]['sample_items'] = array_slice(array_values($items[$categoryKey] ?? []), 0, self::SAMPLE_ITEMS);
        }

        return $entries;
    }

    /**
     * Sends the entries to the model in batches and persists the valid answers. Entries the run may not
     * ask about (call cap, budget, AI off) are counted as deferred.
     *
     * @param  list<array<string, mixed>>  $entries  each with a string `key`
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $parse  answer => label columns, null = invalid
     * @param  callable(array<string, array<string, mixed>>): int  $persist
     * @return array{0: int, 1: int} [rows written, entries deferred]
     */
    private function askInBatches(Organization $organization, string $kind, array $entries, callable $parse, callable $persist): array
    {
        $written = 0;
        $deferred = 0;
        $batchSize = max(1, (int) config('menu.intelligence.classification_batch'));

        foreach (array_chunk($entries, $batchSize) as $batch) {
            if ($this->stopAsking || $this->modelCallsLeft === 0) {
                $deferred += count($batch);

                continue;
            }

            $answers = $this->ask($organization, $kind, $batch);
            if ($answers === null) {
                $this->stopAsking = true;
                $deferred += count($batch);

                continue;
            }

            $rows = [];
            foreach ($answers as $key => $answer) {
                $columns = $parse($answer);
                if ($columns !== null) {
                    $rows[$key] = $columns + ['source' => 'auto', 'confidence' => $answer['confidence']];
                }
            }
            $written += $persist($rows);
        }

        return [$written, $deferred];
    }

    /**
     * One model call, recorded as a system usage entry. Null when the budget is spent (stop asking).
     * Answers for keys that were not asked about, or without a numeric confidence, are dropped.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, array<string, mixed>>|null key => answer, confidence clamped to 0..1
     */
    private function ask(Organization $organization, string $kind, array $entries): ?array
    {
        if ($this->budget->projectedExceeds($organization, 0.0)) {
            return null;
        }

        if ($this->modelCallsLeft !== null) {
            $this->modelCallsLeft--;
        }

        $startedAt = hrtime(true);
        $plural = $kind === ComponentClassifier::KIND_COMPONENT ? 'recipe components' : 'menu categories';

        /** @var StructuredAgentResponse $response */
        $response = (new ComponentClassifier($organization, $kind))->prompt(
            "Label these {$plural}:\n".json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $this->usage->record(
            organization: $organization,
            user: null,
            channel: 'system',
            provider: $response->meta->provider ?? 'unknown',
            model: $response->meta->model ?? 'unknown',
            step: 0,
            usage: $response->usage,
            latencyMs: (int) round((hrtime(true) - $startedAt) / 1e6),
        );

        $asked = array_flip(array_map(fn (array $entry): string => (string) $entry['key'], $entries));
        $answers = [];

        foreach ((array) ($response->structured['items'] ?? []) as $answer) {
            if (! is_array($answer) || ! is_string($answer['key'] ?? null) || ! isset($asked[$answer['key']])) {
                continue;
            }
            if (! is_numeric($answer['confidence'] ?? null)) {
                continue;
            }

            $answer['confidence'] = max(0.0, min(1.0, (float) $answer['confidence']));
            $answers[$answer['key']] = $answer;
        }

        return $answers;
    }

    /**
     * Upserts labels, re-reading owner rows first so an owner decision made while the model was
     * answering is not overwritten. Returns how many rows were written.
     *
     * @template TModel of ComponentClass|CategoryProfile
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, array<string, mixed>>  $rows  key => columns (name, label, source, confidence)
     */
    private function persist(Builder $query, string $keyColumn, int $organizationId, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $owned = (clone $query)
            ->where('organization_id', $organizationId)
            ->where('source', 'owner')
            ->whereIn($keyColumn, array_map('strval', array_keys($rows)))
            ->pluck($keyColumn)
            ->flip()
            ->all();

        $now = now();
        $written = 0;
        foreach ($rows as $key => $columns) {
            if (isset($owned[$key])) {
                continue;
            }

            (clone $query)->updateOrCreate(
                ['organization_id' => $organizationId, $keyColumn => (string) $key],
                ['confidence' => round((float) $columns['confidence'], 2), 'classified_at' => $now] + $columns,
            );
            $written++;
        }

        return $written;
    }

    /**
     * Keys that are not to be judged this run: owner rows, and auto/rule rows judged within
     * `rejudge_days`, whatever their confidence.
     *
     * @template TModel of ComponentClass|CategoryProfile
     *
     * @param  Builder<TModel>  $query
     * @return array<string, true>
     */
    private function settledKeys(Builder $query, string $keyColumn, int $organizationId): array
    {
        $since = now()->subDays((int) config('menu.intelligence.rejudge_days'));
        $settled = [];

        $rows = $query->where('organization_id', $organizationId)->get([$keyColumn, 'source', 'classified_at']);
        foreach ($rows as $row) {
            /** @var Carbon|null $classifiedAt */
            $classifiedAt = $row->classified_at;
            $recent = $classifiedAt !== null && $classifiedAt->greaterThanOrEqualTo($since);

            if ($row->source === 'owner' || ($recent && in_array($row->source, ['auto', 'rule'], true))) {
                $settled[(string) $row->getAttribute($keyColumn)] = true;
            }
        }

        return $settled;
    }

    /** @return Builder<ComponentClass> */
    private function components(): Builder
    {
        return ComponentClass::withoutGlobalScopes();
    }

    /** @return Builder<CategoryProfile> */
    private function categories(): Builder
    {
        return CategoryProfile::withoutGlobalScopes();
    }

    /** @param  list<string>  $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
