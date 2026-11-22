<?php

declare(strict_types=1);

namespace App\Ai\Tools\Context;

use App\Ai\Tools\Concerns\CachesToolResults;
use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        return 'Resolve a period preset, custom from/to dates, or month numbers into exact calendar date ranges. For months the user names without a year («شهر 9 و10», «سبتمبر»), pass months and never guess the year: the tool picks the latest such months up to today.';
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
            'months' => $schema->array()
                ->items($schema->integer()->min(1)->max(12))
                ->description('Consecutive month numbers in calendar order, e.g. [9, 10] or [12, 1]. Resolves to the latest such months up to today (the current month runs to today). Use instead of from/to when the user gave no year.'),
            'year' => $schema->integer()
                ->description('Year of the last month in months, only when the user stated it.'),
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
            'months' => ['sometimes', 'nullable', 'array', 'min:1', 'max:12'],
            'months.*' => ['integer', 'between:1,12'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
        ]);

        if (! empty($validated['months'])) {
            $validated = ['preset' => PeriodPreset::custom->value, 'compare' => $validated['compare'] ?? null]
                + $this->monthsRange(array_map('intval', $validated['months']), isset($validated['year']) ? (int) $validated['year'] : null);
        }

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

    /**
     * Date range of consecutive months given without a year: the last month is the latest one that has
     * started by today (or falls in $year), earlier months step back across New Year, and an open month ends today.
     *
     * @param  non-empty-list<int>  $months
     * @return array{from: string, to: string}
     */
    private function monthsRange(array $months, ?int $year): array
    {
        $today = CarbonImmutable::now($this->userTimezone())->startOfDay();
        $last = $months[count($months) - 1];
        $lastYear = $year ?? ($last <= $today->month ? $today->year : $today->year - 1);

        $firstYear = $lastYear;
        for ($i = count($months) - 1; $i > 0; $i--) {
            if ($months[$i - 1] > $months[$i]) {
                $firstYear--;
            }
        }

        $from = CarbonImmutable::create($firstYear, $months[0], 1, 0, 0, 0, $today->timezone);
        $to = CarbonImmutable::create($lastYear, $last, 1, 0, 0, 0, $today->timezone)->endOfMonth()->startOfDay();

        return ['from' => $from->toDateString(), 'to' => $to->min($today)->toDateString()];
    }
}
