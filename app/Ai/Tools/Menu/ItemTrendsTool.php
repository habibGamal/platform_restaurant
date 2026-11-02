<?php

declare(strict_types=1);

namespace App\Ai\Tools\Menu;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Menu\ItemKey;
use App\Domain\Menu\ItemRoles;
use App\Domain\Menu\ItemTrends;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class ItemTrendsTool implements ReportsProgress, Tool
{
    use FormatsToolResult, UsesReportFilters;

    public function __construct(public readonly User $user) {}

    public function name(): string
    {
        return 'item_trends';
    }

    public function description(): string
    {
        return 'Compare marketable item quantities in the last 28 days against the preceding 28 days, anchored to the period end.';
    }

    public function progressLabel(): string
    {
        return 'بشوف الأصناف اللي بتزيد واللي بتقل…';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->reportFilterSchema($schema);
    }

    public function handle(Request $request): string
    {
        try {
            $args = $request->validate($this->reportFilterRules());
            $filters = $this->reportFiltersFromArgs($args);
            if ($filters->channel !== null) {
                return $this->formatError('unsupported_filter', 'التحليل ده لا يدعم التصفية حسب قناة البيع.');
            }
            $current = new Period($filters->period->to->subDays(27), $filters->period->to);
            $filters = new ReportFilters(
                PeriodPreset::custom, $current, ComparisonMode::previous, $current->shift(-28),
                $filters->branchIds, $filters->branchSetId, $filters->restaurantId,
                $filters->likeForLike, $filters->channel, $filters->granularity,
            );
            $query = MetricsQuery::for($this->user, $filters);
            $branches = $query->branchIds();
            $organization = $this->user->organization;
            if ($organization === null) {
                throw new AuthorizationException;
            }
            $history = new Period($filters->period->to->subDays(55), $filters->period->to);
            $keys = $query->scoped('agg_product_daily', $history)->selectRaw(ItemKey::SQL.' AS item_key')->distinct()->pluck('item_key')->filter(fn ($key) => $key !== null)->map(fn ($key) => (string) $key)->values()->all();
            $roles = app(ItemRoles::class)->ensureClassified($organization, array_values($keys));
            $result = app(ItemTrends::class)->run($query, $roles);
            $summary = $result['status'] === 'ok' ? 'لقيت '.count($result['rising']).' أصناف بتزيد و'.count($result['falling']).' أصناف بتقل.' : ($result['reason'] ?? 'البيانات غير كافية.');
            $branchMap = $this->resolveBranchNames($query, $branches);

            return $this->formatEnvelope($current, $current->shift(-28), $branchMap, $result, $this->buildCompleteness($query, $history, $branchMap), ['change_pct' => '(current_qty - previous_qty) / previous_qty'], ['الاكتمال محسوب على فترتي المقارنة مع بعض: 56 يوم.'], preset: PeriodPreset::custom, comparisonMode: ComparisonMode::previous, summary: $summary);
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
