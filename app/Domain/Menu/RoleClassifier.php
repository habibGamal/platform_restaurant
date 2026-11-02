<?php

declare(strict_types=1);

namespace App\Domain\Menu;

use App\Ai\Agents\ItemRoleClassifier;
use App\Domain\AiBilling\BudgetGuard;
use App\Domain\AiBilling\UsageRecorder;
use App\Domain\Assistant\AiAccess;
use App\Models\CategoryRoleAssignment;
use App\Models\ItemRoleAssignment;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Fills in `item_roles` / `category_roles` for an organization.
 *
 * Order of work: data-only roles first (no model), then one model call for the categories that have
 * no stored role, items inherit confident category roles, and only what is left goes to the model
 * item by item. Owner assignments are never written over and owner items are never sent to the model.
 */
final class RoleClassifier
{
    /** The behaviour window ends on the organization's latest completed business date. */
    private const WINDOW_DAYS = 90;

    private const BATCH_SIZE = 100;

    /** Items listed per category when asking the model to judge a category. */
    private const CATEGORY_SAMPLE = 12;

    public function __construct(
        private readonly RoleSignals $signals,
        private readonly ItemRoles $itemRoles,
        private readonly AiAccess $access,
        private readonly BudgetGuard $budget,
        private readonly UsageRecorder $usage,
    ) {}

    /**
     * @param  list<string>|null  $onlyItemKeys  restrict the work to these items (null = every item sold in the window)
     * @return array{classified: int, skipped: int} skipped counts items left untouched: owner-set, already
     *                                              trusted, or (when AI is off or the budget is spent) not attempted
     */
    public function classify(Organization $organization, ?array $onlyItemKeys = null): array
    {
        if ($this->aiUnavailable($organization)) {
            return ['classified' => 0, 'skipped' => count($onlyItemKeys ?? [])];
        }

        $to = $this->latestBusinessDate($organization->id);
        if ($to === null) {
            return ['classified' => 0, 'skipped' => 0];
        }

        $all = $this->signals->compute($organization->id, $to->subDays(self::WINDOW_DAYS - 1), $to);
        $scope = $onlyItemKeys === null ? $all : array_intersect_key($all, array_flip($onlyItemKeys));

        $ownerKeys = array_flip($this->ownerKeys($organization->id));
        $trusted = $this->itemRoles->mapFor($organization->id);

        $classified = 0;
        $skipped = 0;
        $hard = [];
        $pending = [];

        foreach ($scope as $key => $signal) {
            $key = (string) $key;

            if (isset($ownerKeys[$key])) {
                $skipped++;

                continue;
            }

            $hardRole = RoleSignals::hardRole($signal);
            if ($hardRole !== null) {
                if ($trusted->roleOf($key) === $hardRole) {
                    $skipped++;
                } else {
                    $hard[$key] = [$hardRole, 'signal', 1.0];
                }

                continue;
            }

            if ($trusted->isTrusted($key)) {
                $skipped++;

                continue;
            }

            $pending[$key] = $signal;
        }

        $classified += $this->persist($organization->id, $hard);

        if ($pending === []) {
            return ['classified' => $classified, 'skipped' => $skipped];
        }

        $categoryRoles = $this->classifyCategories($organization, $pending, $all);

        $threshold = (float) config('ai.roles.trusted_confidence', 0.70);
        $inherited = [];
        $remaining = [];
        foreach ($pending as $key => $signal) {
            $category = $signal->categoryName !== null
                ? ($categoryRoles[ItemKey::normalizeCategory($signal->categoryName)] ?? null)
                : null;

            if ($category !== null && $category[0] !== null && $category[1] >= $threshold) {
                $inherited[$key] = [$category[0], 'auto', $category[1]];
            } else {
                $remaining[$key] = $signal;
            }
        }

        $classified += $this->persist($organization->id, $inherited);

        foreach (array_chunk($remaining, self::BATCH_SIZE, true) as $batch) {
            $answers = $this->ask($organization, 'items', array_map(
                fn (ItemSignals $s): array => [
                    'key' => $s->itemKey,
                    'name' => $s->name,
                    'category' => $s->categoryName,
                    'price_vs_median' => round($s->priceVsMedian, 2),
                    'attach_rate' => round($s->attachRate, 2),
                    'avg_qty' => round($s->avgQtyPerOrder, 2),
                ],
                array_values($batch),
            ));

            if ($answers === null) {
                break;
            }

            $rows = [];
            foreach ($answers as $key => [$role, $confidence]) {
                if ($role !== null) {
                    $rows[$key] = [$role, 'auto', $confidence];
                }
            }
            $classified += $this->persist($organization->id, $rows);
        }

        return ['classified' => $classified, 'skipped' => $skipped];
    }

    /**
     * Stored role per normalized category name for the categories of the pending items, asking the
     * model about the ones that have no row yet.
     *
     * @param  array<string, ItemSignals>  $pending
     * @param  array<string, ItemSignals>  $all  every item in the window, used to describe a category
     * @return array<string, array{0: ?ItemRole, 1: float}> category key => [role (null = mixed), confidence]
     */
    private function classifyCategories(Organization $organization, array $pending, array $all): array
    {
        $needed = [];
        foreach ($pending as $signal) {
            if ($signal->categoryName !== null) {
                $needed[ItemKey::normalizeCategory($signal->categoryName)] ??= $signal->categoryName;
            }
        }

        $stored = $this->storedCategories($organization->id, array_keys($needed));
        $missing = array_diff_key($needed, $stored->all());

        if ($missing !== []) {
            $itemsByCategory = [];
            foreach ($all as $signal) {
                if ($signal->categoryName !== null) {
                    $itemsByCategory[ItemKey::normalizeCategory($signal->categoryName)][] = $signal;
                }
            }

            $entries = [];
            foreach ($missing as $categoryKey => $displayName) {
                $items = $itemsByCategory[$categoryKey] ?? [];
                usort($items, fn (ItemSignals $a, ItemSignals $b): int => $b->ordersWith <=> $a->ordersWith);

                $entries[] = [
                    'key' => (string) $categoryKey,
                    'name' => $displayName,
                    'items' => array_map(fn (ItemSignals $s): array => [
                        'name' => $s->name,
                        'price_vs_median' => round($s->priceVsMedian, 2),
                        'attach_rate' => round($s->attachRate, 2),
                        'avg_qty' => round($s->avgQtyPerOrder, 2),
                    ], array_slice($items, 0, self::CATEGORY_SAMPLE)),
                ];
            }

            foreach (array_chunk($entries, self::BATCH_SIZE) as $chunk) {
                $answers = $this->ask($organization, 'categories', $chunk, allowMixed: true);
                if ($answers === null) {
                    break;
                }

                foreach ($answers as $categoryKey => [$role, $confidence]) {
                    CategoryRoleAssignment::query()->updateOrCreate(
                        ['organization_id' => $organization->id, 'category_key' => (string) $categoryKey],
                        ['role' => $role?->value, 'confidence' => round($confidence, 2), 'source' => 'auto'],
                    );
                }
            }

            $stored = $this->storedCategories($organization->id, array_keys($needed));
        }

        $roles = [];
        foreach ($stored as $categoryKey => $row) {
            $roles[(string) $categoryKey] = [
                $row->role !== null ? ItemRole::tryFrom($row->role) : null,
                (float) $row->confidence,
            ];
        }

        return $roles;
    }

    /**
     * @param  list<int|string>  $categoryKeys
     * @return Collection<string, CategoryRoleAssignment>
     */
    private function storedCategories(int $organizationId, array $categoryKeys): Collection
    {
        /** @var Collection<string, CategoryRoleAssignment> */
        return CategoryRoleAssignment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('category_key', $categoryKeys)
            ->get(['category_key', 'role', 'confidence'])
            ->keyBy('category_key');
    }

    /**
     * One model call, recorded as a system usage entry. Null when the budget is spent (stop asking).
     * Answers for keys that were not asked about, or with an unknown role, are dropped.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, array{0: ?ItemRole, 1: float}>|null key => [role (null = mixed), confidence]
     */
    private function ask(Organization $organization, string $kind, array $entries, bool $allowMixed = false): ?array
    {
        if ($this->budget->projectedExceeds($organization, 0.0)) {
            return null;
        }

        $startedAt = hrtime(true);

        /** @var StructuredAgentResponse $response */
        $response = (new ItemRoleClassifier($organization))->prompt(
            "Label these menu {$kind}:\n".json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
            if (! is_array($answer) || ! isset($answer['key'], $answer['role']) || ! is_string($answer['key']) || ! is_string($answer['role'])) {
                continue;
            }
            if (! isset($asked[$answer['key']]) || ! is_numeric($answer['confidence'] ?? null)) {
                continue;
            }

            $mixed = $allowMixed && $answer['role'] === ItemRoleClassifier::MIXED;
            $role = ItemRole::tryFrom($answer['role']);
            if ($role === null && ! $mixed) {
                continue;
            }

            $answers[$answer['key']] = [$role, max(0.0, min(1.0, (float) $answer['confidence']))];
        }

        return $answers;
    }

    /**
     * Upsert item roles, never touching an owner row. Returns how many rows were written.
     *
     * @param  array<string, array{0: ItemRole, 1: string, 2: float}>  $rows  item key => [role, source, confidence]
     */
    private function persist(int $organizationId, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $rows = array_diff_key($rows, array_flip($this->ownerKeys($organizationId)));
        $now = now();

        $payload = [];
        foreach ($rows as $key => [$role, $source, $confidence]) {
            $payload[] = [
                'organization_id' => $organizationId,
                'item_key' => (string) $key,
                'role' => $role->value,
                'source' => $source,
                'confidence' => round($confidence, 2),
                'classified_at' => $now,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            ItemRoleAssignment::query()->upsert(
                $chunk,
                ['organization_id', 'item_key'],
                ['role', 'source', 'confidence', 'classified_at'],
            );
        }

        return count($payload);
    }

    /** @return array<int, string> */
    private function ownerKeys(int $organizationId): array
    {
        return ItemRoleAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('source', 'owner')
            ->pluck('item_key')
            ->map(fn ($key): string => (string) $key)
            ->all();
    }

    private function aiUnavailable(Organization $organization): bool
    {
        return ! $this->access->allowsOrganization($organization)
            || $this->budget->projectedExceeds($organization, 0.0);
    }

    private function latestBusinessDate(int $organizationId): ?CarbonImmutable
    {
        $latest = DB::table('wh_orders')
            ->where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->whereNull('deleted_at')
            ->max('business_date');

        return $latest !== null ? CarbonImmutable::parse((string) $latest) : null;
    }
}
