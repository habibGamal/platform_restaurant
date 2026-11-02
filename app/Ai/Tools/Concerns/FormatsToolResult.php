<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Domain\Metrics\Completeness;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Models\Branch;
use App\Models\User;

/**
 * Trait providing compact JSON result envelope formatting, branch anonymization,
 * completeness warnings, size enforcement (6KB target, 16KB hard cap), and structured errors.
 *
 * @property-read User $user
 */
trait FormatsToolResult
{
    /**
     * Target payload size in bytes.
     */
    protected const TARGET_BYTES = 6 * 1024;

    /**
     * Hard cap payload size in bytes.
     */
    protected const HARD_CAP_BYTES = 16 * 1024;

    /**
     * Build the compact standard tool JSON envelope.
     *
     * @param  array<int, string>  $branchMap
     * @param  array<string, mixed>  $metrics
     * @param  array{summary?: array{complete_branches: int, total_branches: int}|null, warnings?: list<string>}  $completeness
     * @param  array<string, string>  $definitions
     * @param  list<string>  $notes
     * @param  string|null  $summary  a one-line colloquial Arabic summary, placed at the top level when given
     */
    public function formatEnvelope(
        Period $period,
        ?Period $comparison,
        array $branchMap,
        array $metrics,
        array $completeness,
        array $definitions,
        array $notes = [],
        ?PeriodPreset $preset = null,
        ?ComparisonMode $comparisonMode = null,
        ?string $summary = null,
    ): string {
        $periodData = [
            'from' => $period->from->toDateString(),
            'to' => $period->to->toDateString(),
        ];
        if ($preset !== null) {
            $periodData['preset'] = $preset->value;
        }

        $comparisonData = null;
        if ($comparison !== null) {
            $comparisonData = [
                'from' => $comparison->from->toDateString(),
                'to' => $comparison->to->toDateString(),
            ];
            if ($comparisonMode !== null) {
                $comparisonData['mode'] = $comparisonMode->value;
            }
        }

        $envelope = $summary !== null ? ['summary' => $summary] : [];
        $envelope += [
            'period' => $periodData,
            'comparison' => $comparisonData,
            'branches' => $branchMap,
            'currency' => 'EGP',
            'metrics' => $metrics,
            'completeness' => [
                'summary' => $completeness['summary'] ?? null,
                'warnings' => $completeness['warnings'] ?? [],
            ],
            'definitions' => $definitions,
            'notes' => $notes,
        ];

        return $this->enforcePayloadSize($envelope);
    }

    /**
     * Resolve branch names and anonymize when the user cannot see other branch names.
     *
     * @param  list<int>  $branchIds
     * @return array<int, string>
     */
    public function resolveBranchNames(MetricsQuery $query, array $branchIds): array
    {
        $canSeeOther = $query->canSeeOtherBranchNames();
        /** @var list<int> $userBranchIds */
        $userBranchIds = $this->user->branches()->pluck('branches.id')->map(fn ($id): int => (int) $id)->all();

        $branchModels = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id');

        $result = [];
        $index = 1;

        foreach ($branchIds as $id) {
            $branch = $branchModels->get($id);
            $branchName = $branch !== null ? (string) $branch->name : "فرع {$id}";

            if ($canSeeOther) {
                $result[$id] = $branchName;
            } elseif (count($branchIds) === 1 && in_array($id, $userBranchIds, true)) {
                $result[$id] = $branchName;
            } else {
                $result[$id] = "فرع {$index}";
                $index++;
            }
        }

        return $result;
    }

    /**
     * Build the completeness summary and branch-level warnings for the given query and period.
     *
     * @param  array<int, string>  $branchMap
     * @return array{summary: array{complete_branches: int, total_branches: int}, warnings: list<string>}
     */
    public function buildCompleteness(MetricsQuery $query, Period $period, array $branchMap): array
    {
        $completeness = Completeness::for($query, $period);
        $summary = $completeness->summary();
        $warnings = [];
        $totalDays = count($period->dates());

        // One line per branch and problem (not one per day), so the model reads it as a caveat, not a wall of errors.
        foreach ($query->branchIds() as $id) {
            if ($completeness->isBranchComplete($id)) {
                continue;
            }

            $branchName = $branchMap[$id] ?? "فرع {$id}";
            $byStatus = ['missing' => [], 'closed' => []];
            foreach ($period->dates() as $date) {
                $status = $completeness->statusFor($id, $date->toDateString());
                if (isset($byStatus[$status])) {
                    $byStatus[$status][] = $date->toDateString();
                }
            }

            foreach (['missing' => 'مفقودة', 'closed' => 'غير مطابقة'] as $status => $label) {
                $dates = $byStatus[$status];
                if ($dates !== []) {
                    $range = count($dates) === 1 ? $dates[0] : $dates[0].' إلى '.end($dates);
                    $warnings[] = "بيانات {$branchName} {$label} في ".count($dates)." من {$totalDays} يوم ({$range}).";
                }
            }
        }

        return [
            // Counts branches, not days: a branch is complete only when every day in the period is reconciled.
            'summary' => [
                'complete_branches' => (int) ($summary['complete'] ?? 0),
                'total_branches' => (int) ($summary['total'] ?? 0),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Format a structured error payload in JSON.
     *
     * @param  array<string, mixed>  $extra
     */
    public function formatError(string $error, string $messageAr, array $extra = []): string
    {
        return (string) json_encode(array_merge([
            'error' => $error,
            'message_ar' => $messageAr,
        ], $extra), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Enforce size constraints (target 6 KB, hard cap 16 KB) by trimming lists and adding truncated: true.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function enforcePayloadSize(
        array $envelope,
        int $targetBytes = self::TARGET_BYTES,
        int $maxBytes = self::HARD_CAP_BYTES,
    ): string {
        $json = (string) json_encode($envelope, JSON_UNESCAPED_UNICODE);

        if (strlen($json) <= $maxBytes) {
            return $json;
        }

        $envelope['truncated'] = true;

        // Trim series if present
        if (isset($envelope['metrics']['series']) && is_array($envelope['metrics']['series'])) {
            while (count($envelope['metrics']['series']) > 1 && strlen((string) json_encode($envelope, JSON_UNESCAPED_UNICODE)) > $maxBytes) {
                array_pop($envelope['metrics']['series']);
            }
        }

        // Trim by_branch if present
        if (isset($envelope['metrics']['by_branch']) && is_array($envelope['metrics']['by_branch'])) {
            while (count($envelope['metrics']['by_branch']) > 1 && strlen((string) json_encode($envelope, JSON_UNESCAPED_UNICODE)) > $maxBytes) {
                array_pop($envelope['metrics']['by_branch']);
            }
        }

        // Trim completeness warnings if present
        if (isset($envelope['completeness']['warnings']) && is_array($envelope['completeness']['warnings'])) {
            while (count($envelope['completeness']['warnings']) > 1 && strlen((string) json_encode($envelope, JSON_UNESCAPED_UNICODE)) > $maxBytes) {
                array_pop($envelope['completeness']['warnings']);
            }
        }

        // Repeat across nested lists until the whole envelope fits, not merely one subtree.
        $json = (string) json_encode($envelope, JSON_UNESCAPED_UNICODE);
        while (strlen($json) > $maxBytes) {
            $previous = $json;
            $envelope = $this->trimRecursively($envelope, $maxBytes);
            $json = (string) json_encode($envelope, JSON_UNESCAPED_UNICODE);
            if ($json === $previous) {
                // Oversized scalar data cannot be reduced by trimming lists.
                return (string) json_encode(['error' => 'payload_too_large', 'message_ar' => 'النتيجة كبيرة جداً للعرض.', 'truncated' => true], JSON_UNESCAPED_UNICODE);
            }
        }

        return $json;
    }

    /**
     * Recursively trim arrays to fit within the byte budget.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function trimRecursively(array $data, int $maxBytes): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value) && count($value) > 0) {
                    $half = (int) floor(count($value) / 2);
                    $data[$key] = array_slice($value, 0, $half);
                } else {
                    /** @var array<string, mixed> $value */
                    $data[$key] = $this->trimRecursively($value, $maxBytes);
                }

                if (strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE)) <= $maxBytes) {
                    return $data;
                }
            }
        }

        return $data;
    }

    /**
     * Format a KPI value: round numbers to 2 dp, ratios 0-1 to 4 dp.
     */
    public function formatMetricNumber(string $kpiCode, ?float $val): ?float
    {
        if ($val === null) {
            return null;
        }

        if ($this->isRatioMetric($kpiCode)) {
            return round($val, 4);
        }

        return round($val, 2);
    }

    /**
     * Check if a KPI code or name represents a ratio (0-1).
     */
    public function isRatioMetric(string $kpi): bool
    {
        $kpi = strtoupper($kpi);

        return in_array($kpi, [
            'K10', // Gross margin
            'K15', // Net margin
            'K16', // Food cost %
            'K17', // Waste %
            'K18', // Labor %
            'K19', // Prime cost %
            'K21', // Cancel rate
            'K22', // Return rate
            'K23', // Target achievement
            'K24', // Growth
            'GROSS_MARGIN',
            'NET_MARGIN',
            'FOOD_COST_PCT',
            'WASTE_PCT',
            'LABOR_PCT',
            'PRIME_COST_PCT',
            'CANCEL_RATE',
            'RETURN_RATE',
            'TARGET_ACHIEVEMENT',
            'GROWTH',
        ], true);
    }
}
