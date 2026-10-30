<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\Completeness;
use App\Domain\Metrics\Kpi;
use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodResolver;
use App\Domain\Metrics\Reports\BranchRankingReport\BranchRankingData;
use App\Domain\Metrics\Reports\BranchRankingReport\BranchRankingRowDto;
use App\Domain\Metrics\Reports\BranchRankingReport\ExcludedBranchDto;
use App\Domain\Metrics\Reports\BranchRankingReport\KpiLeaderDto;
use App\Domain\Metrics\Reports\BranchRankingReport\KpiScoreDto;
use App\Domain\Metrics\Reports\BranchRankingReport\RankHistoryData;
use App\Domain\Targets\BranchTargetStatus;
use App\Domain\Targets\TargetAchievement;
use App\Domain\Tenancy\AccessibleBranches;
use App\Models\Branch;
use App\Models\ScoringProfile;
use App\Support\BranchColor;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class BranchRankingReport
{
    /**
     * @var array<string, float>
     */
    public const array DEFAULT_WEIGHTS = [
        'net_margin' => 25.0,
        'sales_growth' => 20.0,
        'target_achievement' => 20.0,
        'food_cost_pct' => 15.0,
        'waste_pct' => 10.0,
        'avg_ticket_growth' => 5.0,
        'cancel_rate' => 5.0,
    ];

    /**
     * @var array<string, string>
     */
    public const array KPI_DIRECTIONS = [
        'net_margin' => 'higher',
        'sales_growth' => 'higher',
        'target_achievement' => 'higher',
        'food_cost_pct' => 'lower',
        'waste_pct' => 'lower',
        'avg_ticket_growth' => 'higher',
        'cancel_rate' => 'lower',
    ];

    /**
     * @var array<string, string>
     */
    public const array KPI_LABELS = [
        'net_margin' => 'هامش صافي الربح',
        'sales_growth' => 'نمو المبيعات',
        'target_achievement' => 'تحقيق المستهدف',
        'food_cost_pct' => 'نسبة تكلفة الخامات',
        'waste_pct' => 'نسبة الهالك',
        'avg_ticket_growth' => 'نمو متوسط الطلب',
        'cancel_rate' => 'نسبة الإلغاء',
    ];

    private ?BranchRankingData $cachedData = null;

    public function __construct(
        public readonly MetricsQuery $query,
    ) {}

    public static function for(MetricsQuery $query): self
    {
        return new self($query);
    }

    /**
     * @return array<string, float>
     */
    public function scoringWeights(): array
    {
        $orgId = $this->query->user->organization_id;
        if (! $orgId) {
            return self::DEFAULT_WEIGHTS;
        }

        /** @var ScoringProfile|null $profile */
        $profile = ScoringProfile::query()->where('organization_id', $orgId)->first();
        if (! $profile) {
            return self::DEFAULT_WEIGHTS;
        }

        /** @var array<string, mixed>|string|null $rawWeights */
        $rawWeights = $profile->getAttribute('weights');
        if (is_string($rawWeights)) {
            $decoded = json_decode($rawWeights, true);
            $rawWeights = is_array($decoded) ? $decoded : null;
        }

        if (! is_array($rawWeights)) {
            return self::DEFAULT_WEIGHTS;
        }

        $weights = [];
        foreach (self::DEFAULT_WEIGHTS as $key => $defaultWeight) {
            $weights[$key] = isset($rawWeights[$key]) && is_numeric($rawWeights[$key])
                ? (float) $rawWeights[$key]
                : $defaultWeight;
        }

        return $weights;
    }

    public function data(): BranchRankingData
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }

        $user = $this->query->user();
        $filters = $this->query->filters();
        $period = $filters->period;
        $comparison = $filters->comparison;

        $canSeeGroup = $this->query->canSeeGroup();
        /** @var array<string, mixed>|string|null $orgSettings */
        $orgSettings = $user->organization?->getAttribute('settings');
        if (is_string($orgSettings)) {
            $decodedSettings = json_decode($orgSettings, true);
            $orgSettings = is_array($decodedSettings) ? $decodedSettings : [];
        }
        $showNamesSetting = is_array($orgSettings) ? (bool) ($orgSettings['show_names_to_managers'] ?? false) : false;
        $canSeeOtherBranchNames = $this->query->canSeeOtherBranchNames() || $showNamesSetting;

        $branchIds = $this->query->branchIds();
        $userBranchIds = array_values(AccessibleBranches::ids($user));

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id');

        $weights = $this->scoringWeights();
        $singleBranchMode = count($branchIds) <= 1;

        // 1. Completeness check
        $completeness = Completeness::for($this->query, $period);
        $coverage = $completeness->perBranchCoverage();

        $eligibleBranchIds = [];
        $excludedBranches = [];

        foreach ($branchIds as $bId) {
            $isComplete = $completeness->isBranchComplete($bId);
            $branchModel = $branches->get($bId);
            $branchName = $this->resolveBranchName($bId, $branchModel, $canSeeOtherBranchNames, $userBranchIds);

            if ($isComplete) {
                $eligibleBranchIds[] = $bId;
            } else {
                $branchCoverage = $coverage[$bId] ?? ['complete' => 0, 'total' => 0];
                $dates = $period->dates();
                $lastStatus = 'missing';
                foreach ($dates as $date) {
                    $st = $completeness->statusFor($bId, $date->toDateString());
                    if ($st === 'missing' || $st === 'closed') {
                        $lastStatus = $st;
                        break;
                    }
                }

                $excludedBranches[] = new ExcludedBranchDto(
                    branchId: $bId,
                    branchName: $branchName,
                    reason: 'مستبعد لنقص البيانات',
                    completeDays: $branchCoverage['complete'],
                    totalDays: $branchCoverage['total'],
                    status: $lastStatus,
                );
            }
        }

        // If after excluding incomplete branches we have <= 1 branch, single branch mode applies
        if (count($eligibleBranchIds) <= 1) {
            $singleBranchMode = true;
        }

        // 2. Fetch Metrics for eligible branches
        $currentKpis = ! empty($eligibleBranchIds) ? $this->query->byBranch($period) : [];
        $comparisonKpis = (! empty($eligibleBranchIds) && $comparison !== null) ? $this->query->byBranch($comparison) : [];

        // Targets for net_sales
        $targetStatuses = [];
        if (! empty($eligibleBranchIds)) {
            $now = CarbonImmutable::now('Africa/Cairo');
            $statuses = TargetAchievement::for($this->query, 'net_sales', $now);
            foreach ($statuses as $st) {
                $targetStatuses[$st->branchId] = $st;
            }
        }

        // 3. Extract Raw KPI values per branch
        $rawKpis = [];
        foreach ($eligibleBranchIds as $bId) {
            $kpi = $currentKpis[$bId] ?? KpiValues::zero();
            $compKpi = $comparisonKpis[$bId] ?? null;
            /** @var BranchTargetStatus|null $tgt */
            $tgt = $targetStatuses[$bId] ?? null;

            $salesGrowth = $compKpi !== null ? Kpi::growth($kpi->net_sales_after_returns, $compKpi->net_sales_after_returns) : null;
            $avgTicketGrowth = $compKpi !== null ? Kpi::growth($kpi->avgTicket(), $compKpi->avgTicket()) : null;
            $tgtAchievement = ($tgt !== null && $tgt->status !== 'no_target') ? $tgt->achievementToDate : null;

            $rawKpis[$bId] = [
                'net_margin' => $kpi->netMargin(),
                'sales_growth' => $salesGrowth,
                'target_achievement' => $tgtAchievement,
                'food_cost_pct' => $kpi->foodCostPct(),
                'waste_pct' => $kpi->wastePct(),
                'avg_ticket_growth' => $avgTicketGrowth,
                'cancel_rate' => $kpi->cancelRate(),
            ];
        }

        // 4. Compute Percentile Scores for each KPI
        $kpiScoresByBranch = [];
        foreach (array_keys(self::DEFAULT_WEIGHTS) as $kpiKey) {
            $dir = self::KPI_DIRECTIONS[$kpiKey];
            $kpiValues = [];
            foreach ($eligibleBranchIds as $bId) {
                $kpiValues[$bId] = $rawKpis[$bId][$kpiKey] ?? null;
            }

            $scores = self::computePercentileScores($kpiValues, $dir);

            foreach ($eligibleBranchIds as $bId) {
                $kpiScoresByBranch[$bId][$kpiKey] = $scores[$bId] ?? null;
            }
        }

        // 5. Compute Overall Score per Branch (handling unavailable KPIs and weight redistribution)
        $overallScores = [];
        $hasUnavailableFlags = [];
        $effectiveWeightsByBranch = [];

        foreach ($eligibleBranchIds as $bId) {
            $availWeight = 0.0;
            $weightedScore = 0.0;
            $hasUnavail = false;

            foreach ($weights as $kpiKey => $weight) {
                $score = $kpiScoresByBranch[$bId][$kpiKey] ?? null;
                if ($score !== null) {
                    $availWeight += $weight;
                    $weightedScore += ($score * $weight);
                } else {
                    $hasUnavail = true;
                }
            }

            if ($availWeight > 0.0) {
                $overallScores[$bId] = round($weightedScore / $availWeight, 2);
                foreach ($weights as $kpiKey => $weight) {
                    $score = $kpiScoresByBranch[$bId][$kpiKey] ?? null;
                    $effectiveWeightsByBranch[$bId][$kpiKey] = $score !== null ? round(($weight / $availWeight) * 100.0, 1) : 0.0;
                }
            } else {
                $overallScores[$bId] = null;
                $effectiveWeightsByBranch[$bId] = array_fill_keys(array_keys($weights), 0.0);
            }

            $hasUnavailableFlags[$bId] = $hasUnavail;
        }

        // 6. Compute Ranks (with ties: 1, 2, 2, 4)
        $currentRanks = self::rankBranches($overallScores);

        // 7. Previous Period Ranks for Delta calculation
        $prevPeriod = $comparison ?? PeriodResolver::comparison($period, ComparisonMode::previous, $filters->preset);
        $prevRanks = $prevPeriod !== null ? $this->computePeriodRanks($prevPeriod, $branchIds, $weights) : [];

        // 8. Build BranchRankingRowDto objects
        $rankedBranches = [];
        foreach ($eligibleBranchIds as $bId) {
            $branchModel = $branches->get($bId);
            $branchName = $this->resolveBranchName($bId, $branchModel, $canSeeOtherBranchNames, $userBranchIds);
            $isAnonymized = ! $canSeeOtherBranchNames && ! in_array($bId, $userBranchIds, true);
            $isCurrentManager = in_array($bId, $userBranchIds, true);

            $rank = $singleBranchMode ? null : ($currentRanks[$bId] ?? null);
            $prevRank = $prevRanks[$bId] ?? null;
            $rankDelta = ($rank !== null && $prevRank !== null) ? ($prevRank - $rank) : null;

            if ($singleBranchMode || $rankDelta === null) {
                $deltaFormatted = '—';
                $deltaColor = 'gray';
            } elseif ($rankDelta > 0) {
                $deltaFormatted = "▲ +{$rankDelta}";
                $deltaColor = 'success';
            } elseif ($rankDelta < 0) {
                $deltaFormatted = '▼ '.abs($rankDelta);
                $deltaColor = 'danger';
            } else {
                $deltaFormatted = '=';
                $deltaColor = 'gray';
            }

            // Build KpiScoreDto map
            $kpiDtos = [];
            foreach (self::DEFAULT_WEIGHTS as $kpiKey => $origWeight) {
                $raw = $rawKpis[$bId][$kpiKey] ?? null;
                $sc = $kpiScoresByBranch[$bId][$kpiKey] ?? null;
                $effWeight = $effectiveWeightsByBranch[$bId][$kpiKey] ?? $origWeight;

                $kpiDtos[$kpiKey] = new KpiScoreDto(
                    kpiKey: $kpiKey,
                    kpiLabel: self::KPI_LABELS[$kpiKey],
                    rawValue: $raw,
                    formattedValue: $this->formatKpiValue($kpiKey, $raw),
                    score: $singleBranchMode ? null : $sc,
                    weight: $origWeight,
                    effectiveWeight: $effWeight,
                    direction: self::KPI_DIRECTIONS[$kpiKey],
                    isUnavailable: $raw === null,
                );
            }

            // Determine top 2 helped and 2 hurt KPIs
            $helpedKpis = [];
            $hurtKpis = [];
            if (! $singleBranchMode) {
                $validScores = array_filter($kpiDtos, fn (KpiScoreDto $d): bool => $d->score !== null);
                uasort($validScores, fn (KpiScoreDto $a, KpiScoreDto $b): int => ($b->score <=> $a->score));
                $helpedKpis = array_slice(array_values($validScores), 0, 2);

                uasort($validScores, fn (KpiScoreDto $a, KpiScoreDto $b): int => ($a->score <=> $b->score));
                $hurtKpis = array_slice(array_values($validScores), 0, 2);
            }

            $rankedBranches[] = new BranchRankingRowDto(
                branchId: $bId,
                branchName: $branchName,
                isAnonymized: $isAnonymized,
                isCurrentManagerBranch: $isCurrentManager,
                overallScore: $singleBranchMode ? null : ($overallScores[$bId] ?? null),
                rank: $rank,
                previousRank: $prevRank,
                rankDelta: $rankDelta,
                rankDeltaFormatted: $deltaFormatted,
                rankDeltaColor: $deltaColor,
                hasUnavailableKpi: $hasUnavailableFlags[$bId] ?? false,
                kpiScores: $kpiDtos,
                helpedKpis: $helpedKpis,
                hurtKpis: $hurtKpis,
                isComplete: true,
            );
        }

        // Sort ranked branches by rank
        usort($rankedBranches, function (BranchRankingRowDto $a, BranchRankingRowDto $b): int {
            if ($a->rank === null && $b->rank === null) {
                return 0;
            }
            if ($a->rank === null) {
                return 1;
            }
            if ($b->rank === null) {
                return -1;
            }

            return $a->rank <=> $b->rank;
        });

        // 9. Podium (Top 3 and Bottom 3)
        $top3Podium = [];
        $bottom3Podium = [];
        if (! $singleBranchMode) {
            $top3Podium = array_slice($rankedBranches, 0, 3);
            if (count($rankedBranches) >= 4) {
                $bottom3Podium = array_reverse(array_slice($rankedBranches, -3));
            }
        }

        // 10. Per-KPI Leaders (best & worst with value)
        $kpiLeaders = [];
        if (! $singleBranchMode) {
            foreach (self::DEFAULT_WEIGHTS as $kpiKey => $weight) {
                $dir = self::KPI_DIRECTIONS[$kpiKey];
                $bestRow = null;
                $worstRow = null;

                foreach ($rankedBranches as $rRow) {
                    $dto = $rRow->kpiScores[$kpiKey] ?? null;
                    if ($dto === null || $dto->score === null) {
                        continue;
                    }

                    if ($bestRow === null || ($dto->score > $bestRow->kpiScores[$kpiKey]->score)) {
                        $bestRow = $rRow;
                    }
                    if ($worstRow === null || ($dto->score < $worstRow->kpiScores[$kpiKey]->score)) {
                        $worstRow = $rRow;
                    }
                }

                if ($bestRow !== null && $worstRow !== null) {
                    $bestDto = $bestRow->kpiScores[$kpiKey];
                    $worstDto = $worstRow->kpiScores[$kpiKey];

                    $kpiLeaders[] = new KpiLeaderDto(
                        kpiKey: $kpiKey,
                        kpiLabel: self::KPI_LABELS[$kpiKey],
                        direction: $dir,
                        bestBranchName: $bestRow->branchName,
                        bestValue: $bestDto->rawValue,
                        bestFormatted: $bestDto->formattedValue,
                        worstBranchName: $worstRow->branchName,
                        worstValue: $worstDto->rawValue,
                        worstFormatted: $worstDto->formattedValue,
                    );
                }
            }
        }

        // 11. Rank History (Last 6 periods)
        $rankHistory = $this->buildRankHistory(
            period: $period,
            branchIds: $branchIds,
            branches: $branches,
            weights: $weights,
            canSeeOtherBranchNames: $canSeeOtherBranchNames,
            userBranchIds: $userBranchIds,
        );

        $this->cachedData = new BranchRankingData(
            rankedBranches: $rankedBranches,
            excludedBranches: $excludedBranches,
            top3Podium: $top3Podium,
            bottom3Podium: $bottom3Podium,
            kpiLeaders: $kpiLeaders,
            rankHistory: $rankHistory,
            singleBranchMode: $singleBranchMode,
            canSeeOtherBranchNames: $canSeeOtherBranchNames,
            canSeeGroup: $canSeeGroup,
            weights: $weights,
        );

        return $this->cachedData;
    }

    /**
     * Compute percentile scores (0-100) for a set of branch values.
     * Edge case: Fewer than 3 branches -> min-max scaling.
     *
     * @param  array<int, float|null>  $values  branchId => rawValue
     * @return array<int, float|null> branchId => score (0..100)
     */
    public static function computePercentileScores(array $values, string $direction): array
    {
        $valid = [];
        foreach ($values as $bId => $v) {
            if ($v !== null) {
                $valid[$bId] = $v;
            }
        }

        $result = [];
        foreach ($values as $bId => $v) {
            $result[$bId] = null;
        }

        $n = count($valid);
        if ($n === 0) {
            return $result;
        }

        if ($n === 1) {
            $firstKey = array_key_first($valid);
            $result[$firstKey] = 100.0;

            return $result;
        }

        // Fewer than 3 branches -> Min-Max Scaling
        if ($n < 3) {
            $min = min($valid);
            $max = max($valid);

            foreach ($valid as $bId => $val) {
                if ($max == $min) {
                    $result[$bId] = 100.0;
                } else {
                    $score = $direction === 'lower'
                        ? (($max - $val) / ($max - $min)) * 100.0
                        : (($val - $min) / ($max - $min)) * 100.0;
                    $result[$bId] = round($score, 2);
                }
            }

            return $result;
        }

        // 3 or more branches -> Percentile formula: (C_worse + 0.5 * (C_tied - 1)) / (N - 1) * 100
        foreach ($valid as $bId => $val) {
            $cWorse = 0;
            $cTied = 0;

            foreach ($valid as $otherVal) {
                if ($direction === 'lower') {
                    if ($otherVal > $val) {
                        $cWorse++;
                    } elseif ($otherVal == $val) {
                        $cTied++;
                    }
                } else {
                    if ($otherVal < $val) {
                        $cWorse++;
                    } elseif ($otherVal == $val) {
                        $cTied++;
                    }
                }
            }

            $score = (($cWorse + 0.5 * ($cTied - 1)) / ($n - 1)) * 100.0;
            $result[$bId] = round($score, 2);
        }

        return $result;
    }

    /**
     * Assign ranks based on overall scores (descending).
     * Ties get same rank, next rank skipped (1, 2, 2, 4).
     *
     * @param  array<int, float|null>  $scores  branchId => score
     * @return array<int, int|null> branchId => rank
     */
    public static function rankBranches(array $scores): array
    {
        $valid = array_filter($scores, fn (?float $s): bool => $s !== null);
        $ranks = [];

        foreach ($scores as $bId => $score) {
            $ranks[$bId] = null;
        }

        foreach ($valid as $bId => $score) {
            $rank = 1;
            foreach ($valid as $otherScore) {
                if (round($otherScore, 2) > round($score, 2)) {
                    $rank++;
                }
            }
            $ranks[$bId] = $rank;
        }

        return $ranks;
    }

    /**
     * Compute ranks of branches over a specified period.
     *
     * @param  list<int>  $branchIds
     * @param  array<string, float>  $weights
     * @return array<int, int|null>
     */
    private function computePeriodRanks(Period $p, array $branchIds, array $weights): array
    {
        $completeness = Completeness::for($this->query, $p);
        $eligible = [];
        foreach ($branchIds as $bId) {
            if ($completeness->isBranchComplete($bId)) {
                $eligible[] = $bId;
            }
        }

        if (count($eligible) <= 1) {
            $res = [];
            foreach ($branchIds as $bId) {
                $res[$bId] = in_array($bId, $eligible, true) ? 1 : null;
            }

            return $res;
        }

        $byBranch = $this->query->byBranch($p);
        $prevPeriod = $p->shift(-$p->days());
        $prevByBranch = $this->query->byBranch($prevPeriod);

        $now = CarbonImmutable::now('Africa/Cairo');
        $targetStatuses = [];
        $tgtList = TargetAchievement::for($this->query, 'net_sales', $now);
        foreach ($tgtList as $t) {
            $targetStatuses[$t->branchId] = $t;
        }

        $rawKpis = [];
        foreach ($eligible as $bId) {
            $kpi = $byBranch[$bId] ?? KpiValues::zero();
            $compKpi = $prevByBranch[$bId] ?? null;
            /** @var BranchTargetStatus|null $tgt */
            $tgt = $targetStatuses[$bId] ?? null;

            $salesGrowth = $compKpi !== null ? Kpi::growth($kpi->net_sales_after_returns, $compKpi->net_sales_after_returns) : null;
            $avgTicketGrowth = $compKpi !== null ? Kpi::growth($kpi->avgTicket(), $compKpi->avgTicket()) : null;
            $tgtAchievement = ($tgt !== null && $tgt->status !== 'no_target') ? $tgt->achievementToDate : null;

            $rawKpis[$bId] = [
                'net_margin' => $kpi->netMargin(),
                'sales_growth' => $salesGrowth,
                'target_achievement' => $tgtAchievement,
                'food_cost_pct' => $kpi->foodCostPct(),
                'waste_pct' => $kpi->wastePct(),
                'avg_ticket_growth' => $avgTicketGrowth,
                'cancel_rate' => $kpi->cancelRate(),
            ];
        }

        $kpiScores = [];
        foreach (array_keys(self::DEFAULT_WEIGHTS) as $kpiKey) {
            $dir = self::KPI_DIRECTIONS[$kpiKey];
            $kpiValues = [];
            foreach ($eligible as $bId) {
                $kpiValues[$bId] = $rawKpis[$bId][$kpiKey] ?? null;
            }
            $scores = self::computePercentileScores($kpiValues, $dir);
            foreach ($eligible as $bId) {
                $kpiScores[$bId][$kpiKey] = $scores[$bId] ?? null;
            }
        }

        $overallScores = [];
        foreach ($eligible as $bId) {
            $availWeight = 0.0;
            $weightedScore = 0.0;
            foreach ($weights as $kpiKey => $weight) {
                $score = $kpiScores[$bId][$kpiKey] ?? null;
                if ($score !== null) {
                    $availWeight += $weight;
                    $weightedScore += ($score * $weight);
                }
            }
            $overallScores[$bId] = $availWeight > 0.0 ? round($weightedScore / $availWeight, 2) : null;
        }

        return self::rankBranches($overallScores);
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branches
     * @param  array<string, float>  $weights
     * @param  array<int>  $userBranchIds
     */
    private function buildRankHistory(
        Period $period,
        array $branchIds,
        $branches,
        array $weights,
        bool $canSeeOtherBranchNames,
        array $userBranchIds,
    ): RankHistoryData {
        $days = max(1, $period->days());
        $labels = [];
        $historyPeriods = [];

        // 6 periods (oldest to current)
        for ($i = 5; $i >= 0; $i--) {
            $p = $period->shift(-$days * $i);
            $historyPeriods[] = $p;
            $labels[] = $p->from->format('m/d').' - '.$p->to->format('m/d');
        }

        $ranksByPeriod = [];
        foreach ($historyPeriods as $idx => $p) {
            $ranksByPeriod[$idx] = $this->computePeriodRanks($p, $branchIds, $weights);
        }

        $datasets = [];
        foreach ($branchIds as $bId) {
            // If manager cannot see other branch names or group, only show manager's own branch
            if (! $canSeeOtherBranchNames && ! in_array($bId, $userBranchIds, true)) {
                continue;
            }

            $branchModel = $branches->get($bId);
            $label = $this->resolveBranchName($bId, $branchModel, $canSeeOtherBranchNames, $userBranchIds);
            $color = BranchColor::for($bId);

            $data = [];
            foreach ($historyPeriods as $idx => $p) {
                $data[] = $ranksByPeriod[$idx][$bId] ?? null;
            }

            $datasets[] = [
                'label' => $label,
                'data' => $data,
                'borderColor' => $color,
                'backgroundColor' => $color,
                'fill' => false,
                'tension' => 0.2,
                'spanGaps' => true,
            ];
        }

        return new RankHistoryData(labels: $labels, datasets: $datasets);
    }

    /**
     * @param  array<int>  $userBranchIds
     */
    private function resolveBranchName(int $branchId, ?Branch $branch, bool $canSeeOtherNames, array $userBranchIds): string
    {
        if ($branch === null) {
            return "فرع #{$branchId}";
        }

        if ($canSeeOtherNames || in_array($branchId, $userBranchIds, true)) {
            return $branch->name;
        }

        return "فرع #{$branchId}";
    }

    private function formatKpiValue(string $kpiKey, ?float $val): string
    {
        if ($val === null) {
            return '—';
        }

        return match ($kpiKey) {
            'sales_growth', 'avg_ticket_growth' => Format::growth($val),
            default => Format::percent($val),
        };
    }
}
