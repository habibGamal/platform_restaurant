<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\Operations\DataHealthTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Tool;

#[MaxSteps(8)]
#[Timeout(120)]
#[MaxTokens(3000)]
final class DataChecker extends SpecialistAgent
{
    public function name(): string
    {
        return 'data_checker';
    }

    public function description(): string
    {
        return 'Delegate only when specialist tools report completeness warnings. Give the isolated task the findings, completeness warnings, period, authorized branches and exact question. Returns one line, or a short warning when missing or unreconciled data changes the conclusion.';
    }

    protected function roleInstructions(): string
    {
        return 'You check whether missing or unreconciled data changes the supplied findings. Call data_health for the delegated period and branches. Return one line confirming the supported conclusion or a short warning identifying the limitation and its effect. Do not repair data, make unsupported assurances or add recommendations.';
    }

    /** @return iterable<Tool> */
    public function tools(): iterable
    {
        return [new DataHealthTool($this->user)];
    }
}
