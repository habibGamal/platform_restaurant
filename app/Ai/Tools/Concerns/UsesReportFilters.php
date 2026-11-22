<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;

/**
 * Trait providing shared report filter JSON schema, validation rules,
 * and ReportFilters rehydration for AI tools.
 *
 * @property-read User $user
 */
trait UsesReportFilters
{
    /**
     * Shared JSON schema fragment for report filters.
     *
     * @return array<string, Type>
     */
    public function reportFilterSchema(JsonSchema $schema): array
    {
        return [
            'preset' => $schema->string()
                ->enum(PeriodPreset::class)
                ->description('Period preset (today, yesterday, this_week, last_week, this_month, last_month, this_quarter, this_year, custom). Defaults to this_month to date when omitted.'),
            'from' => $schema->string()
                ->format('date')
                ->description('Start date (Y-m-d) when preset is custom.'),
            'to' => $schema->string()
                ->format('date')
                ->description('End date (Y-m-d) when preset is custom.'),
            'compare' => $schema->string()
                ->enum(ComparisonMode::class)
                ->description('Comparison mode (previous, same_weekday_last_week, same_period_last_year, none).'),
            'branches' => $schema->array()
                ->items($schema->integer())
                ->description('Optional branch IDs to scope metrics to. Must be accessible to the user.'),
            'restaurant' => $schema->integer()
                ->description('Optional restaurant ID to scope to all branches of that restaurant.'),
            'set' => $schema->integer()
                ->description('Optional branch set ID.'),
            'like_for_like' => $schema->boolean()
                ->description('Whether to compare only branches active in both current and comparison periods.'),
            'granularity' => $schema->string()
                ->enum(['day', 'week', 'month'])
                ->description('Time granularity for series (day, week, month).'),
        ];
    }

    /**
     * Shared validation rules for report filters.
     *
     * @return array<string, list<mixed>>
     */
    public function reportFilterRules(): array
    {
        return [
            'preset' => ['sometimes', 'nullable', 'string', Rule::enum(PeriodPreset::class)],
            'from' => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:preset,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare' => ['sometimes', 'nullable', 'string', Rule::enum(ComparisonMode::class)],
            'branches' => ['sometimes', 'nullable', 'array'],
            'branches.*' => ['integer'],
            'restaurant' => ['sometimes', 'nullable', 'integer'],
            'set' => ['sometimes', 'nullable', 'integer'],
            'like_for_like' => ['sometimes', 'nullable', 'boolean'],
            'granularity' => ['sometimes', 'nullable', 'string', Rule::in(['day', 'week', 'month'])],
            'channel' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Convert validated arguments into ReportFilters with today set in the org timezone.
     *
     * @param  array<string, mixed>  $args
     */
    public function reportFiltersFromArgs(array $args): ReportFilters
    {
        $timezone = $this->userTimezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $query = $args;
        // ReportFilters reads from/to only under preset=custom and otherwise falls back to "today", so a model
        // that sends dates without the preset would silently get today's (often empty) data. Dates win here,
        // and an omitted period is month to date, as the schema tells the model.
        if (empty($args['preset'])) {
            $query['preset'] = ! empty($args['from']) && ! empty($args['to'])
                ? PeriodPreset::custom->value
                : PeriodPreset::this_month->value;
        }
        if (array_key_exists('like_for_like', $args)) {
            $query['lfl'] = $args['like_for_like'];
        }

        return ReportFilters::fromArray($query, $today);
    }

    /**
     * Resolve the active timezone for the user's organization, falling back to config.
     */
    public function userTimezone(): string
    {
        /** @var string $configuredTz */
        $configuredTz = config('app.timezone', 'Africa/Cairo');

        $org = $this->user->organization;

        return $org !== null ? (string) $org->timezone : $configuredTz;
    }
}
