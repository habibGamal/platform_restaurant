<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\Context\FindBranches;
use App\Ai\Tools\Context\ResolvePeriod;
use App\Ai\Tools\Finance\TargetsTool;
use App\Ai\Tools\Metrics\GetKpis;
use App\Ai\Tools\Operations\BranchRankingTool;
use App\Ai\Tools\Operations\DataHealthTool;
use App\Ai\Tools\Operations\OverviewTool;
use App\Ai\Tools\Operations\PeakHoursTool;
use App\Ai\Tools\Operations\ShiftsWeekdaysTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Skills\Skill;

#[MaxSteps(8)]
#[Timeout(120)]
#[MaxTokens(3000)]
final class OperationsSpecialist extends SpecialistAgent
{
    public function name(): string
    {
        return 'operations_specialist';
    }

    public function description(): string
    {
        return 'Delegate branch operations, peak-hour staffing, waste review or targets follow-up. The isolated task must contain the period, authorized branches and exact question; no parent conversation is available.';
    }

    protected function roleInstructions(): string
    {
        return 'You analyze operational performance, branch differences, timing, waste and target gaps. Use the matching skill. Distinguish observed demand from staffing hypotheses: tools do not establish required headcount. Return compact bullet findings with supporting numbers, reasons and completeness warnings.';
    }

    /** @return iterable<Tool> */
    public function tools(): iterable
    {
        return [
            new ResolvePeriod($this->user),
            new FindBranches($this->user),
            new GetKpis($this->user),
            new BranchRankingTool($this->user),
            new ShiftsWeekdaysTool($this->user),
            new OverviewTool($this->user),
            new PeakHoursTool($this->user),
            new DataHealthTool($this->user),
            new TargetsTool($this->user),
        ];
    }

    /** @return iterable<Skill> */
    public function skills(): iterable
    {
        return $this->loadSkills('operations', ['branch-comparison', 'peak-hours-staffing', 'waste', 'targets-follow-up']);
    }
}
