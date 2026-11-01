<?php

declare(strict_types=1);

namespace App\Ai\Tools\Context;

use App\Ai\Tools\Concerns\CachesToolResults;
use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

final class ResolvePeriod implements Tool
{
    use CachesToolResults, FormatsToolResult, UsesReportFilters;

    public function __construct(
        public readonly User $user,
    ) {}

    public function name(): string
    {
        return 'resolve_period';
    }

    public function description(): string
    {
        return 'Resolve a period preset (or custom from/to dates) and comparison mode into exact calendar date ranges.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'preset' => $schema->string()
                ->enum(PeriodPreset::class)
                ->description('Period preset (today, yesterday, this_week, last_week, this_month, last_month, this_quarter, this_year, custom). Defaults to this_month when omitted.'),
            'from' => $schema->string()
                ->format('date')
                ->description('Start date (Y-m-d) when preset is custom.'),
            'to' => $schema->string()
                ->format('date')
                ->description('End date (Y-m-d) when preset is custom.'),
            'compare' => $schema->string()
                ->enum(ComparisonMode::class)
                ->description('Comparison mode (previous, same_weekday_last_week, same_period_last_year, none). Defaults to standard comparison for the preset.'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            return $this->cached($this->name(), $request->all(), function () use ($request): string {
                return $this->execute($request);
            });
        } catch (AuthorizationException $e) {
            return $this->formatError('forbidden_branch', 'ليس لديك صلاحية للوصول إلى هذا الفرع أو البيانات المطلوبة.');
        } catch (ValidationException $e) {
            return $this->formatError('validation_error', 'بيانات غير صالحة.', ['errors' => $e->errors()]);
        } catch (Throwable $e) {
            report($e);

            return $this->formatError('internal_error', 'حدث خطأ غير متوقع أثناء تجهيز البيانات.');
        }
    }

    protected function execute(Request $request): string
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'preset' => ['sometimes', 'nullable', 'string', Rule::enum(PeriodPreset::class)],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'compare' => ['sometimes', 'nullable', 'string', Rule::enum(ComparisonMode::class)],
        ]);

        $filters = $this->reportFiltersFromArgs($validated);

        $payload = [
            'period' => [
                'preset' => $filters->preset->value,
                'from' => $filters->period->from->toDateString(),
                'to' => $filters->period->to->toDateString(),
                'days' => $filters->period->days(),
            ],
            'comparison' => $filters->comparison !== null ? [
                'mode' => $filters->comparisonMode->value,
                'from' => $filters->comparison->from->toDateString(),
                'to' => $filters->comparison->to->toDateString(),
                'days' => $filters->comparison->days(),
            ] : null,
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
