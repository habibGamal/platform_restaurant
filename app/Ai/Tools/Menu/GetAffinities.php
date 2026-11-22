<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\ReadsPreparedMenu;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Menu\Intelligence\AffinityMiner;
use App\Models\Menu\ItemAffinity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Prepared basket itemsets holding one item, strongest lift first. Rare itemsets can show a huge
 * lift, so together_orders always travels next to it.
 */
final class GetAffinities implements ReportsProgress, Tool
{
    use FormatsToolResult, ReadsPreparedMenu, UsesReportFilters;

    public function __construct(public readonly User $user) {}

    public function name(): string
    {
        return 'get_affinities';
    }

    public function description(): string
    {
        return 'Items bought together with one item in the prepared baskets of the latest requested period: itemsets with together_orders, support, lift and average order value. Judge lift together with together_orders.';
    }

    public function progressLabel(): string
    {
        return 'بشوف الأصناف اللي بتتطلب مع بعض…';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->preparedScopeSchema($schema), [
            'item_key' => $schema->string()->description('The item_key; or pass name.'),
            'name' => $schema->string()->description('The item name, when the key is unknown.'),
            'channel' => $schema->string()->enum(self::CHANNELS)->description('Sales channel; defaults to all.'),
            'size' => $schema->integer()->min(2)->max(4)->description('Optional itemset size (2 = pairs).'),
            'limit' => $schema->integer()->min(1)->max(15)->description('Default 10.'),
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
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(self::CHANNELS)],
            'size' => ['sometimes', 'nullable', 'integer', 'between:2,4'],
            'limit' => ['sometimes', 'nullable', 'integer', 'between:1,15'],
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
        $period = $scope->latestPeriod();

        $sets = AffinityMiner::forItem($scope->organizationId, $period, $item->item_key, (string) ($args['channel'] ?? 'all'), isset($args['size']) ? (int) $args['size'] : null)
            ->take((int) ($args['limit'] ?? 10))
            ->values();
        $metrics = ['status' => 'ok', 'item' => ['item_key' => $item->item_key, 'name' => $item->name], 'affinity_period' => $period];
        if ($sets->isEmpty()) {
            $reason = 'الصنف ده ملوش أصناف بتتطلب معاه بشكل متكرر في الفترة دي.';

            return $this->preparedEnvelope($scope, ['status' => 'insufficient_data', 'reason' => $reason] + $metrics, $reason);
        }

        $keys = array_values(array_unique(array_map('strval', array_merge(...$sets->map(fn (ItemAffinity $s): array => $s->items)->all()))));
        $metrics['itemsets'] = self::present(array_values($sets->all()), $this->preparedItemNames($scope->organizationId, $keys));

        return $this->preparedEnvelope($scope, $metrics, 'لقيت '.$sets->count().' مجموعات أصناف بتتطلب مع '.$item->name.'.');
    }

    /**
     * @param  list<ItemAffinity>  $sets
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    public static function present(array $sets, array $names): array
    {
        return array_map(fn (ItemAffinity $set): array => [
            'items' => array_map(fn ($key): array => ['item_key' => (string) $key, 'name' => $names[$key] ?? (string) $key], $set->items),
            'together_orders' => $set->together_orders,
            'support' => $set->support,
            'lift' => round($set->lift, 2),
            'avg_order_value' => $set->avg_order_value,
            // JSON object keys that look numeric decode as ints.
            'orders_with_each' => array_map(fn ($key, $orders): array => ['item_key' => (string) $key, 'orders' => (int) $orders], array_keys($set->member_orders), $set->member_orders),
        ], $sets);
    }
}
