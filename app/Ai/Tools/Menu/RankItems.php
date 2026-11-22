<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\ReadsPreparedMenu;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Menu\Intelligence\ItemRanker;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Prepared item rankings per strategy, always within "<role>:<price_tier>" segments: without a segment
 * it returns one list per segment, so a family tray is never compared with a single sandwich.
 */
final class RankItems implements ReportsProgress, Tool
{
    use FormatsToolResult, ReadsPreparedMenu, UsesReportFilters;

    private const ROLE_ORDER = ['main', 'side', 'dessert', 'combo'];

    private const TIER_ORDER = ['single', 'sharing', 'family'];

    public function __construct(public readonly User $user) {}

    public function name(): string
    {
        return 'rank_items';
    }

    public function description(): string
    {
        return 'Ranked menu items for a strategy (push_next_month = items to push next month, protect = strong sellers to leave alone, reprice = popular low-margin items, fix_or_drop = weak items), one list per role:price_tier segment, with quantity, sales and margins from the prepared data.';
    }

    public function progressLabel(): string
    {
        return 'برتب الأصناف اللي تستاهل تركيز…';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->preparedScopeSchema($schema), [
            'strategy' => $schema->string()->enum(self::strategies())->required(),
            'segment' => $schema->string()->description('Optional "<role>:<price_tier>", e.g. main:family. Omit to get one list per segment.'),
            'channel' => $schema->string()->enum(self::CHANNELS)->description('Sales channel; defaults to all. Channels other than all are ranked for push_next_month only.'),
            'limit' => $schema->integer()->min(1)->max(15)->description('Items per segment list, default 5.'),
        ]);
    }

    public function handle(Request $request): string
    {
        try {
            return $this->execute($request);
        } catch (AuthorizationException $e) {
            return $this->formatError('forbidden_branch', 'ليس لديك صلاحية للوصول إلى هذا الفرع أو البيانات المطلوبة.');
        } catch (ValidationException $e) {
            return $this->formatError('validation_error', 'بيانات غير صالحة.', ['errors' => $e->errors()]);
        } catch (Throwable $e) {
            report($e);

            return $this->formatError('internal_error', 'حدث خطأ غير متوقع أثناء تجهيز البيانات.');
        }
    }

    private function execute(Request $request): string
    {
        $args = $request->validate(array_merge($this->preparedScopeRules(), [
            'strategy' => ['required', 'string', Rule::in(self::strategies())],
            'segment' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]+:[a-z]+$/'],
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(self::CHANNELS)],
            'limit' => ['sometimes', 'nullable', 'integer', 'between:1,15'],
        ]));
        $scope = $this->preparedScope($args);
        if (is_string($scope)) {
            return $scope;
        }

        $strategy = (string) $args['strategy'];
        $channel = (string) ($args['channel'] ?? ItemRanker::CHANNEL_ALL);
        $limit = (int) ($args['limit'] ?? 5);
        if ($channel !== ItemRanker::CHANNEL_ALL && $strategy !== ItemRanker::PUSH_NEXT_MONTH) {
            return $this->preparedInsufficient($scope, 'الترتيب ده متاح لكل القنوات مع بعض بس.');
        }

        $segments = isset($args['segment']) ? [(string) $args['segment']] : $this->segments($scope, $strategy, $channel);
        $ranker = app(ItemRanker::class);
        $lists = [];
        foreach ($segments as $segment) {
            // One call per segment: an unsegmented top() interleaves segments by rank.
            $entries = $ranker->top($scope->organizationId, $scope->periodKeys, $strategy, $channel, $segment, $limit);
            if ($entries !== []) {
                $lists[$segment] = ['label' => self::segmentLabel($segment), 'items' => $this->enrich($scope, $channel, $entries)];
            }
        }

        if ($lists === []) {
            return $this->preparedInsufficient($scope, 'مفيش أصناف كفاية اتباعت في الفترة دي عشان تترتب.');
        }

        $count = array_sum(array_map(fn (array $list): int => count($list['items']), $lists));
        $summary = 'رتبت '.$count.' صنف في '.count($lists).' مجموعة أصناف متشابهة.';

        return $this->preparedEnvelope($scope, ['status' => 'ok', 'strategy' => $strategy, 'channel' => $channel, 'segments' => $lists], $summary);
    }

    /**
     * Segments ranked for the strategy and channel in the requested periods, mains first, single before family.
     *
     * @return list<string>
     */
    private function segments(PreparedScope $scope, string $strategy, string $channel): array
    {
        $segments = DB::table('item_rankings')
            ->where('organization_id', $scope->organizationId)
            ->whereIn('period_key', $scope->periodKeys)
            ->where('strategy', $strategy)
            ->where('channel', $channel)
            ->distinct()
            ->pluck('segment_key')
            ->map(fn ($segment): string => (string) $segment)
            ->all();

        $position = function (string $segment): array {
            [$role, $tier] = array_pad(explode(':', $segment, 2), 2, '');
            $roleAt = array_search($role, self::ROLE_ORDER, true);
            $tierAt = array_search($tier, self::TIER_ORDER, true);

            return [$roleAt === false ? 99 : $roleAt, $tierAt === false ? 99 : $tierAt, $segment];
        };
        usort($segments, fn (string $a, string $b): int => $position($a) <=> $position($b));

        return $segments;
    }

    /**
     * Ranked entries with their sales over the requested periods (branch 0, this channel).
     *
     * @param  list<array{item_key: string, name: string, segment_key: string, quadrant: string, score: float, breakdown: array<string, array{value: float, weight: float, contribution: float}>|null, per_period: array<string, float>}>  $entries
     * @return list<array<string, mixed>>
     */
    private function enrich(PreparedScope $scope, string $channel, array $entries): array
    {
        $stats = DB::table('item_period_stats')
            ->where('organization_id', $scope->organizationId)
            ->whereIn('period_key', $scope->periodKeys)
            ->where('branch_id', 0)
            ->where('channel', $channel)
            ->whereIn('item_key', array_column($entries, 'item_key'))
            ->groupBy('item_key')
            ->selectRaw('item_key, SUM(qty) AS qty, SUM(net_sales) AS net_sales, SUM(cogs) AS cogs')
            ->get()
            ->keyBy('item_key');

        $rows = [];
        foreach ($entries as $i => $entry) {
            $stat = $stats->get($entry['item_key']);
            $qty = $stat !== null ? (float) $stat->qty : 0.0;
            $net = $stat !== null ? (float) $stat->net_sales : 0.0;
            $profit = $net - ($stat !== null ? (float) $stat->cogs : 0.0);

            $row = [
                'rank' => $i + 1,
                'item_key' => $entry['item_key'],
                'name' => $entry['name'],
                'quadrant' => $entry['quadrant'],
                'score' => $entry['score'],
                'qty' => round($qty, 2),
                'net_sales' => round($net, 2),
                'unit_margin' => $qty > 0 ? round($profit / $qty, 2) : null,
                'margin_pct' => $net > 0 ? round($profit / $net, 4) : null,
            ];
            if ($entry['breakdown'] !== null) {
                $row['factors'] = array_map(fn (array $factor): float => round($factor['value'], 2), $entry['breakdown']);
            }
            if (count($entry['per_period']) > 1) {
                $row['score_by_period'] = $entry['per_period'];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<string> */
    private static function strategies(): array
    {
        return array_map('strval', array_keys((array) config('menu.strategies')));
    }
}
