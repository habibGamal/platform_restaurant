<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Menu\BasketAnalysis;
use App\Domain\Menu\ItemKey;
use App\Domain\Menu\ItemRoles;
use App\Domain\Metrics\MetricsQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class BoughtTogether implements ReportsProgress, Tool
{
    use FormatsToolResult, UsesReportFilters;

    public function __construct(public readonly User $user) {}

    public function name(): string
    {
        return 'bought_together';
    }

    public function description(): string
    {
        return 'Find main items frequently ordered with compatible companions.';
    }

    public function progressLabel(): string
    {
        return 'بشوف الأصناف اللي بتتطلب مع بعض…';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->reportFilterSchema($schema), ['item_key' => $schema->string(), 'channel' => $schema->string()]);
    }

    public function handle(Request $request): string
    {
        try {
            $args = $request->validate(array_merge($this->reportFilterRules(), ['item_key' => ['sometimes', 'nullable', 'string', 'max:64']]));
            $filters = $this->reportFiltersFromArgs($args);
            $query = MetricsQuery::for($this->user, $filters);
            $branches = $query->branchIds();
            $organization = $this->user->organization;
            if ($organization === null) {
                throw new AuthorizationException;
            }
            $keys = $query->scoped('agg_product_daily')->selectRaw(ItemKey::SQL.' AS item_key')
                ->distinct()->pluck('item_key')->filter(fn ($key) => $key !== null)->map(fn ($key) => (string) $key)->values()->all();
            $roles = app(ItemRoles::class)->ensureClassified($organization, array_values($keys));
            $result = app(BasketAnalysis::class)->pairsFor($query, $roles, $args['item_key'] ?? null);
            $summary = $result['status'] === 'ok'
                ? 'لقيت '.count($result['pairs']).' مجموعات أصناف بتتطلب مع بعض.'
                : ($result['reason'] ?? 'البيانات غير كافية.');
            $branchMap = $this->resolveBranchNames($query, $branches);

            return $this->formatEnvelope($filters->period, $filters->comparison, $branchMap, $result, $this->buildCompleteness($query, $filters->period, $branchMap), [], preset: $filters->preset, comparisonMode: $filters->comparisonMode, summary: $summary);
        } catch (AuthorizationException $e) {
            return $this->formatError('forbidden_branch', 'ليس لديك صلاحية للوصول إلى هذا الفرع أو البيانات المطلوبة.');
        } catch (ValidationException $e) {
            return $this->formatError('validation_error', 'بيانات غير صالحة.', ['errors' => $e->errors()]);
        } catch (Throwable $e) {
            report($e);

            return $this->formatError('internal_error', 'حدث خطأ غير متوقع أثناء تجهيز البيانات.');
        }
    }
}
