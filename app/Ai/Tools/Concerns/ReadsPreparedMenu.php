<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Ai\Tools\Menu\PreparedScope;
use App\Domain\Menu\Intelligence\PeriodKeys;
use App\Domain\Menu\Intelligence\PriceTier;
use App\Domain\Menu\ItemRole;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Jobs\BuildMenuIntelligence;
use App\Models\Branch;
use App\Models\Menu\ComboCandidate;
use App\Models\Menu\ItemProfile;
use App\Models\Menu\MenuIntelligenceBuild;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Shared period resolution, access rule and item lookup for the tools that read the nightly-prepared
 * menu intelligence. Prepared data is organization-wide (branch 0), so it is only shown to a user who
 * can see every branch; an unprepared period queues a build and answers insufficient_data meanwhile.
 * Needs FormatsToolResult and UsesReportFilters on the same tool.
 *
 * @property-read User $user
 */
trait ReadsPreparedMenu
{
    public const PREPARING_REASON = 'البيانات لسه بتتجهز للفترة دي، جرّب بعد شوية';

    public const NO_SALES_REASON = 'مفيش مبيعات مسجلة في الفترة دي، فمفيش بيانات منيو جاهزة ليها.';

    public const CHANNELS = ['all', 'dine_in', 'takeaway', 'delivery', 'talabat', 'companies', 'web_delivery', 'web_takeaway'];

    /** @return array<string, Type> */
    public function preparedScopeSchema(JsonSchema $schema): array
    {
        return [
            'months' => $schema->array()
                ->items($schema->string())
                ->description('Prepared months as YYYY-MM in calendar order, e.g. ["2026-09", "2026-10"]. Resolve months the user named without a year with resolve_period first. At most 6.'),
            'period' => $schema->string()
                ->enum([PeriodKeys::ROLLING_90D])
                ->description('rolling_90d = the last 90 days up to yesterday. Use instead of months.'),
            'preset' => $schema->string()
                ->enum(PeriodPreset::class)
                ->description('Alternative to months: only the full months the preset covers are used. Defaults to last_month when no period is given.'),
            'from' => $schema->string()
                ->format('date')
                ->description('Alternative to months: start date (Y-m-d); only the full months between from and to are used.'),
            'to' => $schema->string()
                ->format('date')
                ->description('End date (Y-m-d) with from.'),
            'branches' => $schema->array()
                ->items($schema->integer())
                ->description('Optional. Prepared data covers all branches together; a subset of branches returns insufficient_data.'),
        ];
    }

    /** @return array<string, list<mixed>> */
    public function preparedScopeRules(): array
    {
        return [
            'months' => ['sometimes', 'nullable', 'array', 'max:6'],
            'months.*' => ['string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'period' => ['sometimes', 'nullable', 'string', Rule::in([PeriodKeys::ROLLING_90D])],
            'preset' => ['sometimes', 'nullable', 'string', Rule::enum(PeriodPreset::class)],
            'from' => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branches' => ['sometimes', 'nullable', 'array'],
            'branches.*' => ['integer'],
        ];
    }

    /**
     * The prepared scope for validated arguments, or a finished tool result (validation error or insufficient_data).
     *
     * @param  array<string, mixed>  $args
     *
     * @throws AuthorizationException
     */
    protected function preparedScope(array $args): PreparedScope|string
    {
        $organization = $this->user->organization;
        if ($organization === null) {
            throw new AuthorizationException;
        }
        $today = CarbonImmutable::now($this->userTimezone())->startOfDay();

        $requested = null;
        if (! empty($args['period'])) {
            $keys = [PeriodKeys::ROLLING_90D];
        } elseif (! empty($args['months'])) {
            /** @var list<string> $months */
            $months = $args['months'];
            // YYYY-MM sorts chronologically; ItemRanker::top takes segments from the last period passed.
            $keys = array_values(array_unique($months));
            sort($keys, SORT_STRING);
        } else {
            $requested = $this->reportFiltersFromArgs([
                'preset' => $args['preset'] ?? (! empty($args['from']) && ! empty($args['to']) ? PeriodPreset::custom->value : PeriodPreset::last_month->value),
                'from' => $args['from'] ?? null,
                'to' => $args['to'] ?? null,
            ])->period;
            $keys = self::monthsCovering($requested, $today);
        }

        $ranges = [];
        foreach ($keys as $key) {
            try {
                [$from, $to] = PeriodKeys::range($key, $today);
            } catch (InvalidArgumentException) {
                return $this->formatError('validation_error', 'الفترة المطلوبة لسه مبدأتش، اختار شهر فات.');
            }
            $ranges[$key] = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }

        $filters = ReportFilters::fromArray([
            'preset' => PeriodPreset::custom->value,
            'from' => $ranges === [] ? $requested?->from->toDateString() : min(array_column($ranges, 'from')),
            'to' => $ranges === [] ? $requested?->to->toDateString() : max(array_column($ranges, 'to')),
            'compare' => 'none',
            'branches' => $args['branches'] ?? null,
        ], $today);
        $query = MetricsQuery::for($this->user, $filters);
        $branchIds = $query->branchIds();
        $scope = new PreparedScope((int) $organization->id, $keys, $ranges, $filters, $query, $this->resolveBranchNames($query, $branchIds));

        if ($keys === []) {
            return $this->preparedInsufficient($scope, 'البيانات الجاهزة بتتحسب لشهور كاملة أو لآخر 90 يوم، والفترة دي مش مغطية شهر كامل.');
        }

        // Org-wide figures must not reach a user restricted to (or asking about) some branches only.
        $orgBranches = Branch::withoutGlobalScopes()->where('organization_id', $scope->organizationId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (array_diff($orgBranches, $branchIds) !== []) {
            return $this->preparedInsufficient($scope, 'القوايم والعروض الجاهزة محسوبة على كل الفروع مع بعض، فمش متاحة لفرع أو فروع معينة.');
        }

        // A period is ready once its build finished (the marker), not as soon as some stats rows exist mid-build.
        $builds = MenuIntelligenceBuild::withoutGlobalScopes()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('period_key', $keys)
            ->get()
            ->keyBy('period_key');

        $missing = array_values(array_diff($keys, $builds->keys()->all()));
        if ($missing !== []) {
            // A build triggered by a question keeps the label classifier to a couple of model calls.
            BuildMenuIntelligence::dispatch($scope->organizationId, $missing, classifierCalls: 2);

            return $this->preparedInsufficient($scope, self::PREPARING_REASON);
        }

        if ($builds->every(fn (MenuIntelligenceBuild $build): bool => (int) ($build->rows['stats'] ?? 0) === 0)) {
            return $this->preparedInsufficient($scope, self::NO_SALES_REASON);
        }

        return $scope->withPreparedAt($builds->max(fn (MenuIntelligenceBuild $build): string => $build->completed_at->toDateTimeString()));
    }

    /**
     * The standard envelope for a prepared-data result; metrics carry the periods and when they were prepared.
     *
     * @param  array<string, mixed>  $metrics
     */
    protected function preparedEnvelope(PreparedScope $scope, array $metrics, string $summary): string
    {
        $metrics += ['periods' => $scope->ranges, 'prepared_at' => $scope->preparedAt];

        return $this->formatEnvelope($scope->filters->period, null, $scope->branchMap, $metrics, $this->buildCompleteness($scope->query, $scope->filters->period, $scope->branchMap), [], preset: PeriodPreset::custom, summary: $summary);
    }

    protected function preparedInsufficient(PreparedScope $scope, string $reason): string
    {
        $metrics = ['status' => 'insufficient_data', 'reason' => $reason, 'periods' => $scope->ranges];

        return $this->formatEnvelope($scope->filters->period, null, $scope->branchMap, $metrics, [], [], preset: PeriodPreset::custom, summary: $reason);
    }

    /**
     * The item by key, or by name: Arabic-normalized exact match first, then a single "contains" match.
     *
     * @return array{status: 'ok', profile: ItemProfile}|array{status: 'not_found'|'ambiguous', reason: string, matches: list<array{item_key: string, name: string}>}
     */
    protected function findPreparedItem(int $organizationId, ?string $itemKey, ?string $name): array
    {
        $profiles = ItemProfile::withoutGlobalScopes()->where('organization_id', $organizationId);

        if ($itemKey !== null && $itemKey !== '') {
            $profile = $profiles->where('item_key', $itemKey)->first();

            return $profile !== null
                ? ['status' => 'ok', 'profile' => $profile]
                : ['status' => 'not_found', 'reason' => 'مش لاقي الصنف ده في بيانات المنيو الجاهزة.', 'matches' => []];
        }

        $notFound = ['status' => 'not_found', 'reason' => 'مش لاقي صنف بالاسم ده في بيانات المنيو الجاهزة.', 'matches' => []];
        $needle = self::normalizeItemName((string) $name);
        if ($needle === '') {
            return $notFound;
        }

        $candidates = [];
        foreach ($profiles->get(['item_key', 'name']) as $profile) {
            $candidates[] = ['item_key' => $profile->item_key, 'name' => $profile->name, 'normalized' => self::normalizeItemName($profile->name)];
        }
        $matches = array_values(array_filter($candidates, fn (array $c): bool => $c['normalized'] === $needle));
        if ($matches === []) {
            $matches = array_values(array_filter($candidates, fn (array $c): bool => str_contains($c['normalized'], $needle)));
        }

        if (count($matches) === 1) {
            $profile = ItemProfile::withoutGlobalScopes()->where('organization_id', $organizationId)->where('item_key', $matches[0]['item_key'])->firstOrFail();

            return ['status' => 'ok', 'profile' => $profile];
        }
        if ($matches === []) {
            return $notFound;
        }

        $list = array_map(fn (array $c): array => ['item_key' => $c['item_key'], 'name' => $c['name']], array_slice($matches, 0, 8));

        return ['status' => 'ambiguous', 'reason' => 'لقيت أكتر من صنف بالاسم ده، اختار واحد منهم بالـitem_key.', 'matches' => $list];
    }

    /**
     * Combo candidates of the requested periods: one row per signature (the latest period's), rejected
     * ones never, approved before candidates, then by score.
     *
     * @return list<ComboCandidate>
     */
    protected function preparedCombos(PreparedScope $scope, string $channel, ?string $heroKey = null, ?string $template = null, ?string $status = null, int $limit = 6): array
    {
        $order = array_flip($scope->periodKeys);
        $latest = [];
        $rows = ComboCandidate::withoutGlobalScopes()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('period_key', $scope->periodKeys)
            ->where('channel', $channel)
            ->when($heroKey !== null, fn ($q) => $q->where('hero_key', $heroKey))
            ->when($template !== null, fn ($q) => $q->where('template', $template))
            ->get();
        foreach ($rows as $row) {
            $current = $latest[$row->signature] ?? null;
            if ($current === null || $order[$row->period_key] > $order[$current->period_key]) {
                $latest[$row->signature] = $row;
            }
        }

        $combos = array_values(array_filter(
            $latest,
            fn (ComboCandidate $c): bool => $c->status !== 'rejected' && ($status === null || $c->status === $status),
        ));
        usort($combos, fn (ComboCandidate $a, ComboCandidate $b): int => [$a->status !== 'approved', -$a->score, $a->signature] <=> [$b->status !== 'approved', -$b->score, $b->signature]);

        return array_slice($combos, 0, $limit);
    }

    /**
     * Item names by key (the key itself when there is no profile).
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    protected function preparedItemNames(int $organizationId, array $keys): array
    {
        $names = DB::table('item_profiles')->where('organization_id', $organizationId)->whereIn('item_key', $keys)->pluck('name', 'item_key')->all();
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = isset($names[$key]) ? (string) $names[$key] : $key;
        }

        return $result;
    }

    /** Arabic label of a "<role>:<price_tier>" segment, e.g. «طبق رئيسي - عائلي». */
    public static function segmentLabel(string $segmentKey): string
    {
        [$role, $tier] = array_pad(explode(':', $segmentKey, 2), 2, '');
        $roleLabel = ItemRole::tryFrom($role)?->label() ?? $role;
        $tierLabel = PriceTier::tryFrom($tier)?->label() ?? $tier;

        return trim($roleLabel.' - '.$tierLabel, ' -');
    }

    /** Lowercase, no spaces/tatweel/diacritics, and ة→ه, ى→ي, أ/إ/آ→ا, so «صينيه» finds «صينية». */
    public static function normalizeItemName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = (string) preg_replace('/[\s\x{0640}\x{064B}-\x{065F}\x{0670}]+/u', '', $name);

        return strtr($name, ['ة' => 'ه', 'ى' => 'ي', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']);
    }

    /**
     * Month keys whose whole prepared window lies inside the period (the current month counts up to yesterday).
     *
     * @return list<string>
     */
    private static function monthsCovering(Period $period, CarbonImmutable $today): array
    {
        $keys = [];
        for ($month = $period->from->startOfMonth(); $month <= $period->to; $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');
            try {
                [$from, $to] = PeriodKeys::range($key, $today);
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($period->from->toDateString() <= $from->toDateString() && $period->to->toDateString() >= $to->toDateString()) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
