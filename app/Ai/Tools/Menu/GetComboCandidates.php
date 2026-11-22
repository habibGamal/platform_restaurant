<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\ReadsPreparedMenu;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Menu\Intelligence\ComboTemplate;
use App\Models\Menu\ComboCandidate;
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
 * Prepared combo proposals: one hero main plus template slots (drinks are attach items), priced on the
 * discount ladder at the hero segment's margin floor. Approved ones first; rejected ones never.
 */
final class GetComboCandidates implements ReportsProgress, Tool
{
    use FormatsToolResult, ReadsPreparedMenu, UsesReportFilters;

    public function __construct(public readonly User $user) {}

    public function name(): string
    {
        return 'get_combo_candidates';
    }

    public function description(): string
    {
        return 'Prepared combo offers: one hero main with sides, drinks or dessert, with list total, suggested price, margin, discount, basket evidence and checks. Approved offers come first; rejected offers are never returned.';
    }

    public function progressLabel(): string
    {
        return 'بجيب العروض الجاهزة بهامش ربح آمن…';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->preparedScopeSchema($schema), [
            'hero_key' => $schema->string()->description('Optional hero main item_key.'),
            'hero_name' => $schema->string()->description('Optional hero main name, when the key is unknown.'),
            'template' => $schema->string()->enum(array_column(ComboTemplate::cases(), 'value')),
            'channel' => $schema->string()->enum(self::CHANNELS)->description('Sales channel; defaults to all.'),
            'status' => $schema->string()->enum(['approved', 'candidate'])->description('Optional; by default approved offers first, then candidates.'),
            'limit' => $schema->integer()->min(1)->max(6)->description('Default 3.'),
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
            'hero_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'hero_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'template' => ['sometimes', 'nullable', 'string', Rule::enum(ComboTemplate::class)],
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(self::CHANNELS)],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['approved', 'candidate'])],
            'limit' => ['sometimes', 'nullable', 'integer', 'between:1,6'],
        ]));
        $scope = $this->preparedScope($args);
        if (is_string($scope)) {
            return $scope;
        }

        $heroKey = null;
        if (! empty($args['hero_key']) || ! empty($args['hero_name'])) {
            $found = $this->findPreparedItem($scope->organizationId, $args['hero_key'] ?? null, $args['hero_name'] ?? null);
            if ($found['status'] !== 'ok') {
                return $this->preparedEnvelope($scope, $found, $found['reason']);
            }
            $heroKey = $found['profile']->item_key;
        }

        $combos = $this->preparedCombos($scope, (string) ($args['channel'] ?? 'all'), $heroKey, $args['template'] ?? null, $args['status'] ?? null, (int) ($args['limit'] ?? 3));
        if ($combos === []) {
            return $this->preparedEnvelope($scope, ['status' => 'insufficient_data', 'reason' => 'مفيش عروض جاهزة بهامش ربح آمن للاختيار ده.', 'combos' => []], 'مفيش عروض جاهزة بهامش ربح آمن للاختيار ده.');
        }

        $approved = count(array_filter($combos, fn (ComboCandidate $c): bool => $c->status === 'approved'));
        $summary = 'لقيت '.count($combos).' عروض جاهزة، منهم '.$approved.' متوافق عليهم.';

        return $this->preparedEnvelope($scope, ['status' => 'ok', 'combos' => array_map(fn (ComboCandidate $c): array => $this->present($scope, $c), $combos)], $summary);
    }

    /** @return array<string, mixed> */
    private function present(PreparedScope $scope, ComboCandidate $combo): array
    {
        $evidence = $combo->evidence;
        if (is_array($evidence['itemset'] ?? null)) {
            $evidence['itemset'] = array_values($this->preparedItemNames($scope->organizationId, array_values(array_map('strval', $evidence['itemset']))));
        }
        $names = $this->preparedItemNames($scope->organizationId, array_values(array_filter([$combo->hero_key, $combo->overlaps_combo_key])));

        return [
            'status' => $combo->status,
            'period' => $combo->period_key,
            'template' => $combo->template,
            'template_label' => ComboTemplate::tryFrom($combo->template)?->label(),
            'hero' => ['item_key' => $combo->hero_key, 'name' => $names[$combo->hero_key]],
            'items' => $combo->items,
            'list_total' => $combo->list_total,
            'cost_total' => $combo->cost_total,
            'suggested_price' => $combo->suggested_price,
            'margin_pct' => $combo->margin_pct,
            'discount_pct' => $combo->discount_pct,
            'evidence' => $evidence,
            'checks' => $combo->checks,
            'overlaps_existing_combo' => $combo->overlaps_combo_key !== null ? ['item_key' => $combo->overlaps_combo_key, 'name' => $names[$combo->overlaps_combo_key]] : null,
            'owner_note' => $combo->owner_note,
        ];
    }
}
