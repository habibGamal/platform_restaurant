<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\Context\FindBranches;
use App\Ai\Tools\Context\ResolvePeriod;
use App\Ai\Tools\Finance\ExpensesPurchasesTool;
use App\Ai\Tools\Finance\FoodCostWasteTool;
use App\Ai\Tools\Finance\ProfitabilityTool;
use App\Ai\Tools\Finance\SalesComparisonTool;
use App\Ai\Tools\Finance\SalesForecastTool;
use App\Ai\Tools\Finance\TargetsTool;
use App\Ai\Tools\Metrics\GetKpis;
use App\Ai\Tools\Metrics\GetKpiSeries;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Skills\Skill;

#[MaxSteps(8)]
#[Timeout(120)]
#[MaxTokens(3000)]
final class FinanceSpecialist extends SpecialistAgent
{
    public function name(): string
    {
        return 'finance_specialist';
    }

    public function description(): string
    {
        return 'Delegate profit, costs, expenses, financial comparisons, targets or sales forecasting. The isolated task must contain the period, authorized branches and exact question; no parent conversation is available.';
    }

    protected function roleInstructions(): string
    {
        return 'You analyze financial performance and cost drivers. Use the matching skill and only report tool-computed changes, margins and forecasts. A forecast is a conditional range, never a promise. Return compact bullet findings with supporting numbers, reasons and data limitations.';
    }

    /** @return iterable<Tool> */
    public function tools(): iterable
    {
        return [
            new ResolvePeriod($this->user),
            new FindBranches($this->user),
            new GetKpis($this->user),
            new GetKpiSeries($this->user),
            new ProfitabilityTool($this->user),
            new FoodCostWasteTool($this->user),
            new ExpensesPurchasesTool($this->user),
            new SalesComparisonTool($this->user),
            new TargetsTool($this->user),
            new SalesForecastTool($this->user),
        ];
    }

    /** @return iterable<Skill> */
    public function skills(): iterable
    {
        return $this->loadSkills('finance', ['profit-review', 'cost-check', 'forecast', 'expense-anomalies']);
    }
}
