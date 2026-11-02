<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Menu\MenuEngineeringAnalysis;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ReportFilters;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class MenuEngineering implements ReportsProgress, Tool
{
    use FormatsToolResult, UsesReportFilters;

    public function __construct(public readonly User $user) {}

    public function name(): string
    {
        return 'menu_engineering';
    }

    public function description(): string
    {
        return 'Analyze menu item profitability and popularity by trusted item role.';
    }

    public function progressLabel(): string
    {
        return 'بحسب هوامش وأداء الأصناف…';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->reportFilterSchema($schema), [
            'role' => $schema->string()->enum(['main', 'side', 'dessert', 'combo']),
            'per_branch' => $schema->boolean(),
        ]);
    }

    public function handle(Request $request): string
    {
        try {
            // Owner role changes must take effect immediately in marketing recommendations.
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
        $args = $request->validate(array_merge($this->reportFilterRules(), [
            'role' => ['sometimes', 'nullable', 'string', Rule::in(['main', 'side', 'dessert', 'combo'])],
            'per_branch' => ['sometimes', 'nullable', 'boolean'],
        ]));
        $filters = $this->reportFiltersFromArgs($args);
        if ($filters->channel !== null) {
            return $this->formatError('unsupported_filter', 'تحليل المنيو لا يدعم التصفية حسب قناة البيع.');
        }
        $query = MetricsQuery::for($this->user, $filters);
        $branchIds = $query->branchIds();
        $organization = $this->user->organization;
        if ($organization === null) {
            throw new AuthorizationException;
        }
        $analysis = app(MenuEngineeringAnalysis::class);
        $result = $analysis->run($query, $organization);
        $role = $args['role'] ?? null;
        $summary = $this->summary($result, is_string($role) ? $role : 'main');
        $result = $this->limitResult($result, is_string($role) ? $role : null);

        if ($args['per_branch'] ?? false) {
            $result['by_branch'] = [];
            foreach ($branchIds as $id) {
                $branchFilters = new ReportFilters($filters->preset, $filters->period, $filters->comparisonMode, $filters->comparison, [$id], $filters->branchSetId, $filters->restaurantId, $filters->likeForLike, $filters->channel, $filters->granularity);
                $branchQuery = MetricsQuery::for($this->user, $branchFilters);
                $result['by_branch'][$id] = $this->limitResult($analysis->run($branchQuery, $organization), is_string($role) ? $role : null);
            }
        }

        $branchMap = $this->resolveBranchNames($query, $branchIds);

        return $this->formatEnvelope($filters->period, $filters->comparison, $branchMap, $result, $this->buildCompleteness($query, $filters->period, $branchMap), [], preset: $filters->preset, comparisonMode: $filters->comparisonMode, summary: $summary);
    }

    /** @param array<string, mixed> $result */
    private function summary(array $result, string $role): string
    {
        if ($result['status'] !== 'ok') {
            return (string) $result['reason'];
        }
        $groups = $result['groups'][$role] ?? [];
        $gems = count($groups['hidden_gems'] ?? []);
        $total = array_sum(array_map('count', $groups));
        $roleName = match ($role) {
            'side' => 'جانبية',
            'dessert' => 'حلويات',
            'combo' => 'مجمعة',
            default => 'رئيسية',
        };
        $finding = $gems === 1 ? 'صنف واحد مربح ومش بيتباع كفاية' : "{$gems} أصناف مربحة ومش بتتباع كفاية";

        return "لقيت {$finding} من {$total} أصناف {$roleName}";
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function limitResult(array $result, ?string $role): array
    {
        if (! isset($result['groups'])) {
            return $result;
        }
        if ($role !== null) {
            $result['groups'] = array_intersect_key($result['groups'], [$role => true]);
            $result['floors'] = array_intersect_key($result['floors'], [$role => true]);
            foreach (['new_items', 'unknown_cost'] as $key) {
                $result[$key] = array_values(array_filter($result[$key], fn (array $row): bool => $row['role'] === $role));
            }
        }
        foreach ($result['groups'] as &$quadrants) {
            foreach ($quadrants as &$items) {
                $items = array_slice($items, 0, 8);
            }
            unset($items);
        }
        unset($quadrants);

        return $result;
    }
}
