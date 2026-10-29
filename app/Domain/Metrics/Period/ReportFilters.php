<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Period;

use Carbon\CarbonImmutable;

final readonly class ReportFilters
{
    /**
     * @param  array<int>|null  $branchIds
     */
    public function __construct(
        public PeriodPreset $preset,
        public Period $period,
        public ComparisonMode $comparisonMode,
        public ?Period $comparison,
        public ?array $branchIds,
        public ?int $branchSetId,
        public ?int $restaurantId,
        public bool $likeForLike,
        public ?string $channel,
        public Granularity $granularity,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query, CarbonImmutable $today, ?string $nowTime = null): self
    {
        $preset = PeriodPreset::today;
        if (isset($query['preset']) && is_string($query['preset'])) {
            $preset = PeriodPreset::tryFrom($query['preset']) ?? PeriodPreset::today;
        }

        $from = null;
        $to = null;

        if ($preset === PeriodPreset::custom) {
            $parsedFrom = self::parseDate($query['from'] ?? null);
            $parsedTo = self::parseDate($query['to'] ?? null);

            if ($parsedFrom !== null && $parsedTo !== null && $parsedFrom <= $parsedTo) {
                $from = $parsedFrom;
                $to = $parsedTo;
            } else {
                $preset = PeriodPreset::today;
            }
        }

        $period = PeriodResolver::resolve($preset, $today, $from, $to, $nowTime);

        $comparisonMode = PeriodResolver::defaultComparison($preset);
        if (isset($query['compare']) && is_string($query['compare'])) {
            $comparisonMode = ComparisonMode::tryFrom($query['compare']) ?? $comparisonMode;
        }

        $comparison = PeriodResolver::comparison($period, $comparisonMode, $preset);

        $branchIds = self::parseBranches($query['branches'] ?? null);

        $branchSetId = null;
        if (isset($query['set']) && is_numeric($query['set'])) {
            $setId = (int) $query['set'];
            if ($setId > 0) {
                $branchSetId = $setId;
            }
        }

        $restaurantId = null;
        if (isset($query['restaurant']) && is_numeric($query['restaurant'])) {
            $restId = (int) $query['restaurant'];
            if ($restId > 0) {
                $restaurantId = $restId;
            }
        }

        $likeForLike = false;
        if (isset($query['lfl'])) {
            $lfl = $query['lfl'];
            $likeForLike = ($lfl === '1' || $lfl === 1 || $lfl === true || $lfl === 'true');
        }

        $channel = null;
        if (isset($query['channel']) && is_string($query['channel'])) {
            $trimmed = trim($query['channel']);
            if ($trimmed !== '') {
                $channel = $trimmed;
            }
        }

        $granularity = Granularity::auto($period);
        if (isset($query['granularity']) && is_string($query['granularity'])) {
            $granularity = Granularity::tryFrom($query['granularity']) ?? $granularity;
        }

        return new self(
            preset: $preset,
            period: $period,
            comparisonMode: $comparisonMode,
            comparison: $comparison,
            branchIds: $branchIds,
            branchSetId: $branchSetId,
            restaurantId: $restaurantId,
            likeForLike: $likeForLike,
            channel: $channel,
            granularity: $granularity,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->preset !== PeriodPreset::today) {
            $result['preset'] = $this->preset->value;
        }

        if ($this->preset === PeriodPreset::custom) {
            $result['from'] = $this->period->from->toDateString();
            $result['to'] = $this->period->to->toDateString();
        }

        if ($this->comparisonMode !== PeriodResolver::defaultComparison($this->preset)) {
            $result['compare'] = $this->comparisonMode->value;
        }

        if ($this->branchIds !== null && count($this->branchIds) > 0) {
            $result['branches'] = $this->branchIds;
        }

        if ($this->branchSetId !== null) {
            $result['set'] = $this->branchSetId;
        }

        if ($this->restaurantId !== null) {
            $result['restaurant'] = $this->restaurantId;
        }

        if ($this->likeForLike) {
            $result['lfl'] = '1';
        }

        if ($this->channel !== null) {
            $result['channel'] = $this->channel;
        }

        if ($this->granularity !== Granularity::auto($this->period)) {
            $result['granularity'] = $this->granularity->value;
        }

        return $result;
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Africa/Cairo');
            if ($date === null || $date->format('Y-m-d') !== $value) {
                return null;
            }

            return $date->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int>|null
     */
    private static function parseBranches(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $ids = [];

        if (is_string($value)) {
            foreach (explode(',', $value) as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '' && is_numeric($trimmed)) {
                    $intVal = (int) $trimmed;
                    if ($intVal > 0) {
                        $ids[] = $intVal;
                    }
                }
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                if (is_numeric($item)) {
                    $intVal = (int) $item;
                    if ($intVal > 0) {
                        $ids[] = $intVal;
                    }
                }
            }
        }

        if (empty($ids)) {
            return null;
        }

        return array_values(array_unique($ids));
    }
}
