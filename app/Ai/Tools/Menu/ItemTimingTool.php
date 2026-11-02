<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Menu\ItemTiming;
use App\Domain\Metrics\MetricsQuery;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class ItemTimingTool implements ReportsProgress, Tool
{
    use FormatsToolResult, UsesReportFilters;

    public function __construct(public readonly User $user) {}

    public function name(): string
    {
        return 'item_timing';
    }

    public function description(): string
    {
        return 'Find weekday and hour quantity shares and observed open slots with the lowest demand for a trusted marketable item.';
    }

    public function progressLabel(): string
    {
        return 'بحدد أنسب أوقات تسويق الصنف…';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->reportFilterSchema($schema), ['item_key' => $schema->string()->required(), 'channel' => $schema->string()]);
    }

    public function handle(Request $request): string
    {
        try {
            $args = $request->validate(array_merge($this->reportFilterRules(), ['item_key' => ['required', 'string', 'max:64']]));
            $filters = $this->reportFiltersFromArgs($args);
            $query = MetricsQuery::for($this->user, $filters);
            $branches = $query->branchIds();
            $organization = $this->user->organization;
            if ($organization === null) {
                throw new AuthorizationException;
            }
            $result = app(ItemTiming::class)->run($query, $args['item_key']);
            $summary = $result['status'] === 'ok' ? 'حللت توزيع الطلب على الصنف ولقيت '.count($result['slow_slots']).' فترات هادية.' : ($result['reason'] ?? 'البيانات غير كافية.');
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
