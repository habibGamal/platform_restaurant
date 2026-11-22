<?php

declare(strict_types=1);

namespace App\Ai\Tools\Finance;

use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Ai\Tools\Contracts\ReportsProgress;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Models\Branch;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use JsonSerializable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

abstract class ReportTool implements ReportsProgress, Tool
{
    use FormatsToolResult, UsesReportFilters;

    public function __construct(public readonly User $user) {}

    abstract public function name(): string;

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->reportFilterSchema($schema) + [
            'channel' => $schema->string()->description('Channel filtering is unsupported for this report; omit this field.'),
        ];
    }

    final public function handle(Request $request): string
    {
        try {
            $args = $request->validate($this->reportFilterRules());
            $filters = $this->reportFiltersFromArgs($args);
            $query = MetricsQuery::for($this->user, $filters);
            $branchIds = $query->branchIds();
            $unsupportedFilter = $this->unsupportedFilterReason($query);
            if ($unsupportedFilter !== null) {
                return $this->formatError('unsupported_filter', $unsupportedFilter);
            }
            $metrics = $this->report($query);
            $branchMap = $this->resolveBranchNames($query, $branchIds);
            $nameAliases = [];
            foreach (Branch::withoutGlobalScopes()->whereIn('id', $branchIds)->pluck('name', 'id') as $id => $name) {
                $alias = $branchMap[(int) $id];
                // Some report DTOs carry name-only lists, without the corresponding IDs.
                $nameAliases[$name] = isset($nameAliases[$name]) && $nameAliases[$name] !== $alias ? 'فرع (اسم مخفي)' : $alias;
            }
            $period = $this->completenessPeriod($query, $metrics);

            return $this->formatEnvelope(
                $filters->period, $filters->comparison, $branchMap, $this->compact($metrics, $branchMap, $nameAliases),
                $this->buildCompleteness($query, $period, $branchMap), $this->definitions(),
                ['الصفوف المعروضة لا تتجاوز 10 لكل قائمة.'],
                preset: $filters->preset, comparisonMode: $filters->comparisonMode,
                summary: $this->summarize($metrics, count($branchIds)),
            );
        } catch (AuthorizationException) {
            return $this->formatError('forbidden_branch', 'ليس لديك صلاحية للوصول إلى هذا الفرع أو البيانات المطلوبة.');
        } catch (ValidationException $e) {
            return $this->enforcePayloadSize([
                'error' => 'validation_error', 'message_ar' => 'بيانات غير صالحة.', 'errors' => $e->errors(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return $this->formatError('internal_error', 'حدث خطأ غير متوقع أثناء تجهيز البيانات.');
        }
    }

    /** @return array<string, mixed> */
    abstract protected function report(MetricsQuery $query): array;

    protected function unsupportedFilterReason(MetricsQuery $query): ?string
    {
        return $query->filters()->channel !== null ? 'التقرير ده لا يدعم التصفية حسب قناة البيع.' : null;
    }

    /** @param array<string, mixed> $metrics */
    protected function summarize(array $metrics, int $branchCount): string
    {
        return "جهزت التقرير من بيانات {$branchCount} فروع للفترة المحددة.";
    }

    /**
     * Meaning of fields the model is likely to misread, sent with every result.
     *
     * @return array<string, string>
     */
    protected function definitions(): array
    {
        return [];
    }

    /** Shared wording for the stock-count field, whose sign is the opposite of what its name suggests to models. */
    protected const INVENTORY_VARIANCE_DEFINITION = 'Stock-count difference valued at cost. Positive = shortage (عجز جرد), a loss that lowers profit. Negative = surplus (زيادة جرد), stock found above the books, which raises profit; a large negative value usually means a counting or recording error, not real performance.';

    /** @param array<string, mixed> $metrics */
    protected function completenessPeriod(MetricsQuery $query, array $metrics): Period
    {
        return $query->filters()->period;
    }

    /**
     * Normalize report DTOs before payload enforcement, which only trims arrays.
     *
     * @param  array<string, mixed>  $metrics
     * @param  array<int, string>  $branchMap
     * @param  array<string, string>  $nameAliases
     * @return array<string, mixed>
     */
    private function compact(array $metrics, array $branchMap, array $nameAliases): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalize($metrics, $branchMap, $nameAliases);

        return $normalized;
    }

    /**
     * @param  array<int, string>  $branchMap
     * @param  array<string, string>  $nameAliases
     */
    private function normalize(mixed $value, array $branchMap, array $nameAliases, ?string $context = null): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            if (is_string($value) && in_array($context, ['branchNames', 'missing_expense_branches'], true)) {
                return $nameAliases[$value] ?? $value;
            }
            if (is_string($value) && in_array($context, ['notes', 'reason', 'tooltip'], true)) {
                return strtr($value, $nameAliases);
            }

            return $value;
        }
        $branchId = $value['branchId'] ?? $value['branch_id'] ?? null;
        if (is_int($branchId) && isset($branchMap[$branchId])) {
            foreach (['branchName', 'branch_name', 'branch', 'label'] as $key) {
                if (array_key_exists($key, $value)) {
                    $value[$key] = $branchMap[$branchId];
                }
            }
        }
        if (array_is_list($value)) {
            $value = array_slice($value, 0, 10);
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->normalize($child, $branchMap, $nameAliases, is_string($key) ? $key : $context);
        }

        return $value;
    }
}
