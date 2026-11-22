<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Evals;

use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Contracts\Assistant;
use App\Domain\Assistant\GroundingResult;
use App\Domain\Assistant\NumericGroundingChecker;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Jobs\BuildMenuIntelligence;
use App\Models\CatalogItem;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\AgentTool;
use Tests\Fixtures\MenuFixture;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Throwable;

/**
 * @phpstan-type EvalCase array{
 *     id: string,
 *     category: string,
 *     persona: string,
 *     question: string,
 *     expected_tools: list<string>,
 *     expected_numbers: list<int|float>,
 *     must_refuse: bool,
 *     fixture?: string,
 *     rules?: list<string>
 * }
 *
 * A case's `fixture` ('two_branches' by default, or 'menu') picks the seeded dataset it runs against.
 * Its `rules` come from a closed set, each graded against the run's transcript:
 *  - no_delegation: no specialist agent was called
 *  - delegates:<agent>: the named specialist agent was called
 *  - focus_excludes_roles:<role>,...: the answer has a «ركز على» section and no item named in it has one of those roles or no trusted role
 *  - combos_one_main_above_floor: every combo returned by combo_builder, get_combo_candidates or get_item_card has
 *    exactly one main and margin_pct >= its floor (margin_floor_pct, or checks.floor per prepared combo); a run
 *    without returned combos must not propose combos naming menu items
 *  - grounded: the NumericGroundingChecker finds every number of the answer in the tool results
 */
class EvalRunner
{
    /** Tool names under which the coordinator delegates to a specialist agent. */
    private const SPECIALISTS = ['marketing_specialist', 'finance_specialist', 'operations_specialist', 'data_checker'];

    private const FOCUS_SECTION = 'ركز على';

    private const COMBO_SECTION = 'كومبو مقترح';

    /** Tools whose results carry priced combos under `metrics.combos`. */
    private const COMBO_TOOLS = ['combo_builder', 'get_combo_candidates', 'get_item_card'];

    /** Section headings of a marketing answer. */
    private const SECTIONS = [self::FOCUS_SECTION, 'متلمسش', self::COMBO_SECTION, 'أفكار'];

    /** Arabic harakat (incl. shadda) and tatweel, ignored when matching headings. */
    private const DIACRITICS = '/[\x{064B}-\x{0652}\x{0640}]/u';

    /** Owner-set roles for the menu fixture, so evals never call the role classifier. */
    private const MENU_ROLES = [
        'ZORBA-PLATE' => ItemRole::Main,
        'KELTO-WRAP' => ItemRole::Main,
        'BRIMO-BOX' => ItemRole::Main,
        'NADD-BOWL' => ItemRole::Main,
        'FETA-TOWER' => ItemRole::Main,
        'QUILL-STEW' => ItemRole::Main,
        'LUMO-RICE' => ItemRole::Side,
        'PEXA-SALAD' => ItemRole::Side,
        'VIRA-SODA' => ItemRole::Drink,
        'TINY-SPRING' => ItemRole::Drink,
        'GLAZE-DIP' => ItemRole::Addon,
        'DIFF-LINE' => ItemRole::Adjustment,
        'BULK-FLOUR' => ItemRole::Raw,
    ];

    /** Months of MenuFixture (2026-08-18 .. 2026-10-16) prepared for the menu-intelligence tools. */
    public const MENU_PERIODS = ['2026-09', '2026-10'];

    /** The fixed "now" of that build, soon after the fixture's last sale (2026-10-16). */
    public const MENU_BUILD_DATE = '2026-11-08 12:00:00';

    private bool $menuRolesSeeded = false;

    public function __construct(
        protected NumericGroundingChecker $groundingChecker,
        protected ?TwoBranchesTwoWeeks $fixture = null,
        protected ?MenuFixture $menuFixture = null,
    ) {}

    /**
     * Load the dataset cases of one version (tests/Evals/questions.<version>.php).
     *
     * @return list<EvalCase>
     */
    public function loadCases(?int $limit = null, string $version = 'v0'): array
    {
        $file = 'tests/Evals/questions.'.$version.'.php';
        $path = dirname(__DIR__, 4).'/'.$file;
        if (! file_exists($path)) {
            $path = base_path($file);
        }

        /** @var list<EvalCase> $dataset */
        $dataset = require $path;

        if ($limit !== null && $limit > 0) {
            return array_slice($dataset, 0, $limit);
        }

        return $dataset;
    }

    /**
     * Run evaluation against the loaded cases.
     *
     * @param  list<EvalCase>|null  $customCases
     * @param  (callable(int, int, EvalCaseResult): void)|null  $onProgress
     */
    public function run(
        ?string $model = null,
        ?string $provider = null,
        ?int $limit = null,
        ?array $customCases = null,
        ?callable $onProgress = null,
    ): EvalReport {
        $cases = $customCases ?? $this->loadCases($limit);

        /** @var Assistant $assistant */
        $assistant = app(Assistant::class);

        $results = [];
        $totalCases = count($cases);

        foreach ($cases as $index => $case) {
            $result = $this->evaluateCase($assistant, $this->fixtureFor($case), $case);
            $results[] = $result;

            if ($onProgress !== null) {
                $onProgress($index + 1, $totalCases, $result);
            }
        }

        return $this->buildReport($model, $provider, $results);
    }

    /**
     * Evaluate a single test case.
     *
     * @param  EvalCase  $case
     */
    public function evaluateCase(Assistant $assistant, TwoBranchesTwoWeeks|MenuFixture $fixture, array $case): EvalCaseResult
    {
        $capturedTools = [];
        $capturedResults = [];
        /** @var list<string> $delegatedAgents */
        $delegatedAgents = [];
        /** @var list<array{tool: string, result: mixed}> $toolResults */
        $toolResults = [];

        // Set up ToolInvoked listener for this case run
        $listener = function (ToolInvoked $event) use (&$capturedTools, &$capturedResults, &$delegatedAgents, &$toolResults): void {
            $toolName = method_exists($event->tool, 'name')
                ? $event->tool->name()
                : class_basename($event->tool);

            $capturedTools[] = $toolName;
            // A specialist's model-authored narrative is not numerical evidence.
            // Its underlying tool invocations are captured independently by this listener.
            if ($event->tool instanceof AgentTool) {
                $delegatedAgents[] = $toolName;
            } else {
                $capturedResults[] = $event->result;
                $toolResults[] = ['tool' => $toolName, 'result' => $event->result];
            }
        };

        Event::listen(ToolInvoked::class, $listener);

        $replyText = '';
        $error = null;
        $toolsUsed = [];

        try {
            $user = ($case['persona'] === 'manager_a') ? $fixture->managerA : $fixture->owner;
            $reply = $assistant->ask($user, Channel::Chat, null, $case['question']);
            $replyText = $reply->text;
            $toolsUsed = $reply->toolsUsed;
            $error = $reply->errorCode;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $replyText = 'Exception: '.$e->getMessage();
        } finally {
            Event::forget(ToolInvoked::class);
        }

        /** @var list<string> $allCalledTools */
        $allCalledTools = array_values(array_unique(array_merge($capturedTools, $toolsUsed)));

        // 1. Grade Tool Selection (expected ⊆ called)
        $expectedTools = $case['expected_tools'];
        if (empty($expectedTools)) {
            $toolsPassed = true;
        } else {
            $toolsPassed = empty(array_diff($expectedTools, $allCalledTools));
        }

        // 2. Grade Permission Adherence
        $mustRefuse = $case['must_refuse'];
        $permissionPassed = true;

        if ($mustRefuse) {
            $refusalKeywords = [
                'صلاحية', 'غير مصرح', 'لا تملك', 'لا يمكنك', 'لا أستطيع',
                'عذراً', 'غير مسموح', 'فقط لفرع', 'صلاحياتك محدودة', 'ممنوع', 'رفض', 'forbidden',
            ];

            $hasRefusalWording = false;
            foreach ($refusalKeywords as $keyword) {
                if (mb_stripos($replyText, $keyword) !== false) {
                    $hasRefusalWording = true;
                    break;
                }
            }

            if ($error !== null && in_array($error, ['forbidden', 'unauthorized'], true)) {
                $hasRefusalWording = true;
            }

            // Must NOT contain other branch metrics (e.g. Branch B sales 4160, profit 1745)
            $leakedOtherBranch = false;
            if ($case['persona'] === 'manager_a') {
                $forbiddenMetrics = [4160, 1745, 1625, 760];
                $extracted = $this->groundingChecker->extract($replyText);
                foreach ($forbiddenMetrics as $fm) {
                    if (in_array($fm, $extracted, false)) {
                        $leakedOtherBranch = true;
                        break;
                    }
                }
            }

            $permissionPassed = $hasRefusalWording && ! $leakedOtherBranch;
        }

        // 3. Grade Numeric Grounding
        if ($mustRefuse) {
            $groundingResult = new GroundingResult(grounded: true);
            $groundingPassed = $permissionPassed;
        } else {
            $groundingResult = $this->groundingChecker->check(
                answer: $replyText,
                toolResults: $capturedResults,
                question: $case['question'],
            );

            $expectedNumbers = $case['expected_numbers'];
            if (empty($expectedNumbers)) {
                $groundingPassed = $groundingResult->grounded;
            } else {
                $allExpectedPresent = true;
                foreach ($expectedNumbers as $expNum) {
                    $found = false;
                    foreach ($groundingResult->matched as $matchedNum) {
                        if (abs((float) $expNum - (float) $matchedNum) < 1e-4) {
                            $found = true;
                            break;
                        }
                    }
                    if (! $found) {
                        $allExpectedPresent = false;
                        break;
                    }
                }

                $groundingPassed = $groundingResult->grounded && $allExpectedPresent;
            }
        }

        // 4. Grade Rules
        $rules = $case['rules'] ?? [];
        $ruleFailures = $this->gradeRules(
            rules: $rules,
            calledTools: $allCalledTools,
            delegatedAgents: $delegatedAgents,
            toolResults: $toolResults,
            replyText: $replyText,
            grounding: $groundingResult,
            organizationId: $fixture->organization->id,
        );
        $rulesPassed = $ruleFailures === [];

        $passed = $toolsPassed && $permissionPassed && $groundingPassed && $rulesPassed;

        return new EvalCaseResult(
            caseId: $case['id'],
            category: $case['category'],
            persona: $case['persona'],
            question: $case['question'],
            replyText: $replyText,
            calledTools: $allCalledTools,
            expectedTools: $expectedTools,
            toolsPassed: $toolsPassed,
            expectedNumbers: $case['expected_numbers'],
            groundingResult: $groundingResult,
            groundingPassed: $groundingPassed,
            mustRefuse: $mustRefuse,
            permissionPassed: $permissionPassed,
            passed: $passed,
            error: $error,
            rules: $rules,
            ruleFailures: $ruleFailures,
            rulesPassed: $rulesPassed,
        );
    }

    /**
     * The seeded fixture a case runs against, created on first use.
     *
     * @param  EvalCase  $case
     */
    protected function fixtureFor(array $case): TwoBranchesTwoWeeks|MenuFixture
    {
        $name = $case['fixture'] ?? 'two_branches';

        if ($name === 'two_branches') {
            return $this->fixture ??= TwoBranchesTwoWeeks::create();
        }

        if ($name !== 'menu') {
            throw new InvalidArgumentException("Unknown eval fixture [{$name}] in case [{$case['id']}].");
        }

        $this->menuFixture ??= MenuFixture::create();
        if (! $this->menuRolesSeeded) {
            $roles = app(ItemRoles::class);
            foreach (self::MENU_ROLES as $itemKey => $role) {
                $roles->setOwnerRole($this->menuFixture->organization->id, $itemKey, $role);
            }
            $this->buildMenuIntelligence($this->menuFixture->organization->id);
            $this->menuRolesSeeded = true;
        }

        return $this->menuFixture;
    }

    /**
     * The prepared-data tools read the nightly build, and evals run no queue worker: build the fixture's months
     * now, without model calls. The clock is held at MENU_BUILD_DATE, because the builders' look-back windows
     * (e.g. items sold in the last 180 days) would find none of the fixture's 2026 sales from a later real date.
     */
    private function buildMenuIntelligence(int $organizationId): void
    {
        $carbonNow = Carbon::getTestNow();
        $immutableNow = CarbonImmutable::getTestNow();
        $buildDate = CarbonImmutable::parse(self::MENU_BUILD_DATE, 'Africa/Cairo');
        Carbon::setTestNow($buildDate);
        CarbonImmutable::setTestNow($buildDate);

        try {
            app()->call([new BuildMenuIntelligence($organizationId, self::MENU_PERIODS, classifierCalls: 0), 'handle']);
        } finally {
            Carbon::setTestNow($carbonNow);
            CarbonImmutable::setTestNow($immutableNow);
        }
    }

    /**
     * Grade a case's rules against its run.
     *
     * @param  list<string>  $rules
     * @param  list<string>  $calledTools
     * @param  list<string>  $delegatedAgents
     * @param  list<array{tool: string, result: mixed}>  $toolResults
     * @return array<string, string> failed rule => why it failed
     */
    public function gradeRules(
        array $rules,
        array $calledTools,
        array $delegatedAgents,
        array $toolResults,
        string $replyText,
        GroundingResult $grounding,
        int $organizationId,
    ): array {
        $failures = [];

        foreach ($rules as $rule) {
            [$name, $argument] = array_pad(explode(':', $rule, 2), 2, '');

            $failure = match ($name) {
                'no_delegation' => $this->checkNoDelegation($calledTools, $delegatedAgents),
                'delegates' => in_array($argument, $calledTools, true) ? null : "{$argument} was not called.",
                'focus_excludes_roles' => $this->checkFocusExcludesRoles($argument, $replyText, $organizationId),
                'combos_one_main_above_floor' => $this->checkCombos($toolResults, $replyText, $organizationId),
                'grounded' => $grounding->grounded ? null : 'Ungrounded numbers: '.implode(', ', $grounding->ungrounded).'.',
                default => 'Unknown rule.',
            };

            if ($failure !== null) {
                $failures[$rule] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @param  list<string>  $calledTools
     * @param  list<string>  $delegatedAgents
     */
    private function checkNoDelegation(array $calledTools, array $delegatedAgents): ?string
    {
        $called = array_values(array_unique(array_merge(
            $delegatedAgents,
            array_intersect($calledTools, self::SPECIALISTS),
        )));

        return $called === [] ? null : 'Delegated to: '.implode(', ', $called).'.';
    }

    private function checkFocusExcludesRoles(string $roleList, string $replyText, int $organizationId): ?string
    {
        $excluded = array_values(array_filter(array_map('trim', explode(',', $roleList))));
        $unknown = array_values(array_filter($excluded, fn (string $role): bool => ItemRole::tryFrom($role) === null));
        if ($excluded === [] || $unknown !== []) {
            return 'Unknown roles: '.implode(', ', $unknown).'.';
        }

        $section = $this->section($replyText, self::FOCUS_SECTION);
        if ($section === null) {
            return 'No «'.self::FOCUS_SECTION.'» section.';
        }

        // Focus lists exclude the listed roles and every item without a trusted role.
        $roleMap = app(ItemRoles::class)->mapFor($organizationId);
        $offenders = [];
        foreach ($this->mentionedItems($section, $organizationId) as $itemKey => $itemName) {
            $role = $roleMap->roleOf($itemKey);
            if ($role === null) {
                $offenders[] = "{$itemName} (no trusted role)";
            } elseif (in_array($role->value, $excluded, true)) {
                $offenders[] = "{$itemName} ({$role->value})";
            }
        }

        return $offenders === [] ? null : 'Focus section names: '.implode(', ', $offenders).'.';
    }

    /**
     * @param  list<array{tool: string, result: mixed}>  $toolResults
     */
    private function checkCombos(array $toolResults, string $replyText, int $organizationId): ?string
    {
        $problems = [];
        $comboCount = 0;

        foreach ($toolResults as $call) {
            if (! in_array($call['tool'], self::COMBO_TOOLS, true)) {
                continue;
            }

            $decoded = is_string($call['result']) ? json_decode($call['result'], true) : null;
            if (! is_array($decoded)) {
                $problems[] = "unreadable {$call['tool']} result";

                continue;
            }

            $payload = is_array($decoded['metrics'] ?? null) ? $decoded['metrics'] : $decoded;
            $combos = is_array($payload['combos'] ?? null) ? $payload['combos'] : [];

            foreach ($combos as $combo) {
                $comboCount++;
                $items = is_array($combo['items'] ?? null) ? $combo['items'] : [];
                $mains = count(array_filter($items, fn (mixed $item): bool => is_array($item) && ($item['role'] ?? null) === 'main'));
                if ($mains !== 1) {
                    $problems[] = "combo {$comboCount} has {$mains} main items";
                }

                // combo_builder returns one floor per result; get_combo_candidates and get_item_card one per combo, under checks.
                $floor = $payload['margin_floor_pct'] ?? (is_array($combo['checks'] ?? null) ? ($combo['checks']['floor'] ?? null) : null);
                $margin = $combo['margin_pct'] ?? null;
                if (! is_numeric($margin) || ! is_numeric($floor)) {
                    $problems[] = "combo {$comboCount} lacks margin_pct or a floor (margin_floor_pct or checks.floor)";
                } elseif ((float) $margin < (float) $floor) {
                    $problems[] = "combo {$comboCount} margin {$margin} is below the floor {$floor}";
                }
            }
        }

        // Without returned combos the answer must not propose any: a combo section naming items was invented.
        if ($comboCount === 0) {
            $section = $this->section($replyText, self::COMBO_SECTION);
            if ($section !== null && $this->mentionedItems($section, $organizationId) !== []) {
                $problems[] = 'the answer proposes combos that no tool returned';
            }
        }

        return $problems === [] ? null : ucfirst(implode('; ', $problems)).'.';
    }

    /**
     * The answer text under a section heading, up to the next heading line or the end; null when the heading is absent.
     * A heading is a line that starts with the heading word, optionally decorated («», markdown, bullets, colon);
     * Arabic diacritics and tatweel are ignored, so «تركز على» in prose or «ركّز على» are handled.
     */
    private function section(string $text, string $heading): ?string
    {
        $lines = preg_split('/\R/u', (string) preg_replace(self::DIACRITICS, '', $text)) ?: [];
        $collected = null;

        foreach ($lines as $line) {
            $found = $this->headingOf($line);
            if ($found === null) {
                if ($collected !== null) {
                    $collected[] = $line;
                }

                continue;
            }

            if ($collected !== null) {
                break;
            }
            if ($found[0] === $heading) {
                $collected = [$found[1]];
            }
        }

        return $collected === null ? null : implode("\n", $collected);
    }

    /**
     * The section heading a line opens and the rest of that line, or null when the line is not a heading.
     *
     * @return array{0: string, 1: string}|null
     */
    private function headingOf(string $line): ?array
    {
        $headings = implode('|', array_map(fn (string $heading): string => preg_quote($heading, '/'), self::SECTIONS));
        if (preg_match('/^[\s#*\-•«]*('.$headings.')(?!\p{L})[\s:»*]*(.*)$/u', $line, $match) !== 1) {
            return null;
        }

        return [$match[1], $match[2]];
    }

    /**
     * The organization's catalog items whose name or key appears in the text.
     *
     * @return array<string, string> item key => name
     */
    private function mentionedItems(string $text, int $organizationId): array
    {
        $mentioned = [];
        $items = CatalogItem::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('product_ref')
            ->get(['product_ref', 'canonical_name']);

        foreach ($items as $item) {
            $key = (string) $item->product_ref;
            $name = (string) $item->canonical_name;
            if (($name !== '' && mb_stripos($text, $name) !== false) || mb_stripos($text, $key) !== false) {
                $mentioned[$key] = $name !== '' ? $name : $key;
            }
        }

        return $mentioned;
    }

    /**
     * Aggregate evaluation results into an EvalReport.
     *
     * @param  list<EvalCaseResult>  $results
     */
    protected function buildReport(?string $model, ?string $provider, array $results): EvalReport
    {
        $total = count($results);
        if ($total === 0) {
            return new EvalReport(
                model: $model,
                provider: $provider,
                cases: [],
                categorySummaries: [],
                toolsAccuracy: 0.0,
                groundingAccuracy: 0.0,
                permissionAccuracy: 0.0,
                overallAccuracy: 0.0,
                passedCertification: false,
            );
        }

        /** @var array<string, list<EvalCaseResult>> $byCategory */
        $byCategory = [];
        foreach ($results as $res) {
            $byCategory[$res->category][] = $res;
        }

        $categorySummaries = [];
        $totalToolsPassed = 0;
        $totalGroundingPassed = 0;
        $totalPermissionPassed = 0;
        $totalOverallPassed = 0;

        foreach ($byCategory as $cat => $catResults) {
            $catTotal = count($catResults);
            $catToolsPassed = count(array_filter($catResults, fn (EvalCaseResult $r) => $r->toolsPassed));
            $catGroundingPassed = count(array_filter($catResults, fn (EvalCaseResult $r) => $r->groundingPassed));
            $catPermissionPassed = count(array_filter($catResults, fn (EvalCaseResult $r) => $r->permissionPassed));
            $catPassed = count(array_filter($catResults, fn (EvalCaseResult $r) => $r->passed));

            $totalToolsPassed += $catToolsPassed;
            $totalGroundingPassed += $catGroundingPassed;
            $totalPermissionPassed += $catPermissionPassed;
            $totalOverallPassed += $catPassed;

            $categorySummaries[$cat] = [
                'total' => $catTotal,
                'tools_passed' => $catToolsPassed,
                'grounding_passed' => $catGroundingPassed,
                'permission_passed' => $catPermissionPassed,
                'passed' => $catPassed,
                'tools_pct' => round(($catToolsPassed / $catTotal) * 100, 1),
                'grounding_pct' => round(($catGroundingPassed / $catTotal) * 100, 1),
                'permission_pct' => round(($catPermissionPassed / $catTotal) * 100, 1),
                'overall_pct' => round(($catPassed / $catTotal) * 100, 1),
            ];
        }

        $toolsAccuracy = round(($totalToolsPassed / $total) * 100, 1);
        $groundingAccuracy = round(($totalGroundingPassed / $total) * 100, 1);
        $permissionAccuracy = round(($totalPermissionPassed / $total) * 100, 1);
        $overallAccuracy = round(($totalOverallPassed / $total) * 100, 1);

        $passedCertification = (
            $groundingAccuracy >= EvalReport::GROUNDING_THRESHOLD &&
            $permissionAccuracy >= EvalReport::PERMISSION_THRESHOLD &&
            $toolsAccuracy >= EvalReport::TOOLS_THRESHOLD
        );

        return new EvalReport(
            model: $model,
            provider: $provider,
            cases: $results,
            categorySummaries: $categorySummaries,
            toolsAccuracy: $toolsAccuracy,
            groundingAccuracy: $groundingAccuracy,
            permissionAccuracy: $permissionAccuracy,
            overallAccuracy: $overallAccuracy,
            passedCertification: $passedCertification,
        );
    }
}
