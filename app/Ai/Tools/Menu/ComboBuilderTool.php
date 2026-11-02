<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Menu\ComboBuilder;
use App\Domain\Metrics\MetricsQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class ComboBuilderTool implements ReportsProgress, Tool
{
    use FormatsToolResult, UsesReportFilters;

    public function __construct(public readonly User $user) {}

    public function name(): string
    {
        return 'combo_builder';
    }

    public function description(): string
    {
        return 'Build up to three compatible main and side or dessert offers with safe prices and margins.';
    }

    public function progressLabel(): string
    {
        return 'بجهز عروض أصناف بهامش ربح آمن…';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->reportFilterSchema($schema), ['item_key' => $schema->string()]);
    }

    public function handle(Request $request): string
    {
        try {
            $args = $request->validate(array_merge($this->reportFilterRules(), ['item_key' => ['sometimes', 'nullable', 'string', 'max:64']]));
            $filters = $this->reportFiltersFromArgs($args);
            if ($filters->channel !== null) {
                return $this->formatError('unsupported_filter', 'التحليل ده لا يدعم التصفية حسب قناة البيع.');
            }
            $query = MetricsQuery::for($this->user, $filters);
            $branches = $query->branchIds();
            $organization = $this->user->organization;
            if ($organization === null) {
                throw new AuthorizationException;
            }
            $result = app(ComboBuilder::class)->proposeFor($query, $organization, $args['item_key'] ?? null);
            $summary = $result['status'] === 'ok' ? 'جهزت '.count($result['combos']).' عروض بهامش ربح آمن.' : ($result['reason'] ?? 'البيانات غير كافية.');
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
