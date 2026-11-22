<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\ReadsPreparedMenu;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Menu\Intelligence\AffinityMiner;
use App\Domain\Menu\Intelligence\ComboTemplate;
use App\Domain\Menu\Intelligence\FoodClass;
use App\Domain\Menu\Intelligence\ItemRanker;
use App\Models\Menu\ComboCandidate;
use App\Models\Menu\ItemAffinity;
use App\Models\Menu\ItemPeriodStat;
use App\Models\Menu\ItemProfile;
use App\Models\Menu\ItemRanking;
use App\Models\User;
use App\Support\Weekday;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * The "Item Card": what an item is and serves, how it sold in the requested periods, where it ranks
 * for next month, what it is bought with and the combos built around it.
 */
final class GetItemCard implements ReportsProgress, Tool
{
    use FormatsToolResult, ReadsPreparedMenu, UsesReportFilters;

    public function __construct(public readonly User $user) {}

    public function name(): string
    {
        return 'get_item_card';
    }

    public function description(): string
    {
        return 'The prepared card of one menu item, by item_key or name: what it contains and how many it serves, price tier, season, list price and unit cost, sales per period, its push_next_month rank within its segment, top items bought with it and the combos built around it.';
    }

    public function progressLabel(): string
    {
        return 'بجهز كارت الصنف…';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->preparedScopeSchema($schema), [
            'item_key' => $schema->string()->description('The item_key; or pass name.'),
            'name' => $schema->string()->description('The item name, when the key is unknown (Arabic spelling variants match).'),
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
            'item_key' => ['required_without:name', 'nullable', 'string', 'max:64'],
            'name' => ['required_without:item_key', 'nullable', 'string', 'max:191'],
        ]));
        $scope = $this->preparedScope($args);
        if (is_string($scope)) {
            return $scope;
        }

        $found = $this->findPreparedItem($scope->organizationId, $args['item_key'] ?? null, $args['name'] ?? null);
        if ($found['status'] !== 'ok') {
            return $this->preparedEnvelope($scope, $found, $found['reason']);
        }
        $item = $found['profile'];

        $metrics = [
            'status' => 'ok',
            'item' => $this->profile($scope, $item),
            'stats' => $this->stats($scope, $item->item_key),
            'push_next_month' => $this->ranks($scope, $item->item_key),
            'bought_with' => $this->affinities($scope, $item->item_key),
            'combos' => array_map(fn (ComboCandidate $c): array => [
                'status' => $c->status,
                'template' => ComboTemplate::tryFrom($c->template)?->label() ?? $c->template,
                'items' => array_map(fn (array $i): array => ['name' => $i['name'], 'role' => $i['role'], 'qty' => $i['qty']], $c->items),
                'list_total' => $c->list_total,
                'suggested_price' => $c->suggested_price,
                'margin_pct' => $c->margin_pct,
                'discount_pct' => $c->discount_pct,
                // The floor lets a reader (and the eval combo rule) check the margin as for get_combo_candidates.
                'checks' => ['floor' => $c->checks['floor'] ?? null],
            ], $this->preparedCombos($scope, 'all', $item->item_key, limit: 3)),
        ];

        return $this->preparedEnvelope($scope, $metrics, 'جهزت كارت '.$item->name.'.');
    }

    /** @return array<string, mixed> */
    private function profile(PreparedScope $scope, ItemProfile $item): array
    {
        $members = $item->combo_members ?? [];

        return [
            'item_key' => $item->item_key,
            'name' => $item->name,
            'role' => $item->role,
            'serves_n' => $item->serves_n,
            'price_tier' => $item->price_tier,
            'season' => $item->season,
            'contains' => self::containsLabels($item->contains),
            'main_protein' => $item->main_protein,
            'list_price' => $item->list_price,
            'unit_cost' => $item->unit_cost,
            'has_recipe' => $item->has_recipe,
            'is_existing_combo' => $item->is_existing_combo,
            'combo_members' => $members === [] ? null : array_values($this->preparedItemNames($scope->organizationId, array_map('strval', $members))),
        ];
    }

    /**
     * Arabic labels of the food classes in the recipe, largest share first, without "other". The summed
     * quantities mix units (grams, pieces, ml), so they are never shown and cannot be quoted.
     *
     * @param  array<string, float|int>|null  $contains  food class => summed qty
     * @return list<string>
     */
    private static function containsLabels(?array $contains): array
    {
        $contains ??= [];
        arsort($contains);
        $labels = [];
        foreach (array_keys($contains) as $class) {
            $foodClass = FoodClass::tryFrom((string) $class);
            if ($foodClass !== null && $foodClass !== FoodClass::Other) {
                $labels[] = $foodClass->label();
            }
        }

        return $labels;
    }

    /**
     * Branch 0, channel 'all' stats per requested period.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stats(PreparedScope $scope, string $itemKey): array
    {
        $rows = ItemPeriodStat::withoutGlobalScopes()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('period_key', $scope->periodKeys)
            ->where('branch_id', 0)
            ->where('channel', 'all')
            ->where('item_key', $itemKey)
            ->get()
            ->keyBy('period_key');

        $stats = [];
        foreach ($scope->periodKeys as $period) {
            $row = $rows->get($period);
            if ($row === null) {
                $stats[$period] = null;

                continue;
            }
            $profit = $row->net_sales - $row->cogs;
            $mix = $row->channel_mix ?? [];
            arsort($mix);
            $weekday = [];
            foreach ($row->weekday as $day => $share) {
                $weekday[Weekday::LABELS[(int) $day] ?? (string) $day] = round((float) $share, 3);
            }
            $stats[$period] = [
                'qty' => $row->qty,
                'net_sales' => $row->net_sales,
                'gross_profit' => round($profit, 2),
                'unit_margin' => $row->qty > 0 ? round($profit / $row->qty, 2) : null,
                'margin_pct' => $row->net_sales > 0 ? round($profit / $row->net_sales, 4) : null,
                'orders' => $row->orders,
                'days_sold' => $row->days_sold,
                'open_days' => $row->open_days,
                'daily_velocity' => round($row->velocity, 2),
                'attach_rate' => $row->attach_rate,
                'repeat_customer_share' => $row->repeat_customer_share,
                'velocity_change_vs_previous' => $row->mom_velocity_change,
                'channel_mix' => array_map(fn ($share): float => round((float) $share, 3), $mix),
                'daypart' => array_map(fn ($share): float => round((float) $share, 3), $row->daypart),
                'weekday' => $weekday,
            ];
        }

        return $stats;
    }

    /**
     * The item's push_next_month place (channel all) per requested period, with its segment's size.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function ranks(PreparedScope $scope, string $itemKey): array
    {
        $rankings = ItemRanking::withoutGlobalScopes()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('period_key', $scope->periodKeys)
            ->where('strategy', ItemRanker::PUSH_NEXT_MONTH)
            ->where('channel', 'all')
            ->where('item_key', $itemKey)
            ->get()
            ->keyBy('period_key');

        $ranks = [];
        foreach ($scope->periodKeys as $period) {
            $ranking = $rankings->get($period);
            if ($ranking === null) {
                $ranks[$period] = null;

                continue;
            }
            $ranks[$period] = [
                'rank' => $ranking->rank,
                'of' => DB::table('item_rankings')
                    ->where('organization_id', $scope->organizationId)
                    ->where('period_key', $period)
                    ->where('strategy', ItemRanker::PUSH_NEXT_MONTH)
                    ->where('channel', 'all')
                    ->where('segment_key', $ranking->segment_key)
                    ->count(),
                'segment' => $ranking->segment_key,
                'segment_label' => self::segmentLabel($ranking->segment_key),
                'quadrant' => $ranking->quadrant,
                'score' => $ranking->score,
            ];
        }

        return $ranks;
    }

    /**
     * Top 5 itemsets of size 2-3 holding the item, in the latest requested period.
     *
     * @return array{period: string, itemsets: list<array<string, mixed>>}
     */
    private function affinities(PreparedScope $scope, string $itemKey): array
    {
        $period = $scope->latestPeriod();
        $sets = AffinityMiner::forItem($scope->organizationId, $period, $itemKey)
            ->filter(fn (ItemAffinity $set): bool => $set->size <= 3)
            ->take(5)
            ->all();
        $sets = array_values($sets);
        $keys = $sets === [] ? [] : array_values(array_unique(array_map('strval', array_merge(...array_map(fn (ItemAffinity $s): array => $s->items, $sets)))));

        return ['period' => $period, 'itemsets' => GetAffinities::present($sets, $this->preparedItemNames($scope->organizationId, $keys))];
    }
}
