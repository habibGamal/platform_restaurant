<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\Context\FindBranches;
use App\Ai\Tools\Context\ResolvePeriod;
use App\Ai\Tools\Menu\BoughtTogether;
use App\Ai\Tools\Menu\ComboBuilderTool;
use App\Ai\Tools\Menu\GetAffinities;
use App\Ai\Tools\Menu\GetComboCandidates;
use App\Ai\Tools\Menu\GetItemCard;
use App\Ai\Tools\Menu\ItemTimingTool;
use App\Ai\Tools\Menu\ItemTrendsTool;
use App\Ai\Tools\Menu\MenuEngineering;
use App\Ai\Tools\Menu\RankItems;
use App\Ai\Tools\Metrics\GetKpis;
use App\Ai\Tools\Metrics\QueryMetrics;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Skills\Skill;

#[MaxSteps(8)]
#[Timeout(300)]
#[MaxTokens(3000)]
final class MarketingSpecialist extends SpecialistAgent
{
    public function name(): string
    {
        return 'marketing_specialist';
    }

    public function description(): string
    {
        return 'Delegate marketing priorities, menu analysis, offer design or promotion review. The isolated task must contain the period, authorized branches and exact question; no parent conversation is available.';
    }

    protected function roleInstructions(): string
    {
        return 'You analyze marketing and menu performance. Focus lists rank items within their segment (role and price tier) via rank_items: never compare a family tray with a single meal, and do not substitute highest-volume items. Combos come from get_combo_candidates: exactly one hero main, drinks only as attach items, never addon, adjustment, raw or untrusted-role items, approved offers first, and margin at or above the returned floor. Use get_item_card to explain what an item is and how many it serves. Return compact bullet findings and reasons using the matching skill.';
    }

    /** @return iterable<Tool> */
    public function tools(): iterable
    {
        return [
            new ResolvePeriod($this->user),
            new FindBranches($this->user),
            new MenuEngineering($this->user),
            new BoughtTogether($this->user),
            new ComboBuilderTool($this->user),
            new ItemTrendsTool($this->user),
            new ItemTimingTool($this->user),
            new GetKpis($this->user),
            new QueryMetrics($this->user),
            new RankItems($this->user),
            new GetItemCard($this->user),
            new GetComboCandidates($this->user),
            new GetAffinities($this->user),
        ];
    }

    /** @return iterable<Skill> */
    public function skills(): iterable
    {
        return $this->loadSkills('marketing', ['marketing-focus', 'combo-design', 'menu-cleanup', 'promotion-review']);
    }
}
