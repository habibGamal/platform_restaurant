<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Evals;

use App\Domain\Assistant\GroundingResult;

final readonly class EvalCaseResult
{
    /**
     * @param  list<string>  $calledTools
     * @param  list<string>  $expectedTools
     * @param  list<int|float>  $expectedNumbers
     * @param  list<string>  $rules
     * @param  array<string, string>  $ruleFailures  failed rule => why it failed
     */
    public function __construct(
        public string $caseId,
        public string $category,
        public string $persona,
        public string $question,
        public string $replyText,
        public array $calledTools,
        public array $expectedTools,
        public bool $toolsPassed,
        public array $expectedNumbers,
        public GroundingResult $groundingResult,
        public bool $groundingPassed,
        public bool $mustRefuse,
        public bool $permissionPassed,
        public bool $passed,
        public ?string $error = null,
        public array $rules = [],
        public array $ruleFailures = [],
        public bool $rulesPassed = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'case_id' => $this->caseId,
            'category' => $this->category,
            'persona' => $this->persona,
            'question' => $this->question,
            'reply_text' => $this->replyText,
            'called_tools' => $this->calledTools,
            'expected_tools' => $this->expectedTools,
            'tools_passed' => $this->toolsPassed,
            'expected_numbers' => $this->expectedNumbers,
            'matched_numbers' => $this->groundingResult->matched,
            'ungrounded_numbers' => $this->groundingResult->ungrounded,
            'grounding_passed' => $this->groundingPassed,
            'must_refuse' => $this->mustRefuse,
            'permission_passed' => $this->permissionPassed,
            'passed' => $this->passed,
            'error' => $this->error,
            'rules' => $this->rules,
            'rule_failures' => $this->ruleFailures,
            'rules_passed' => $this->rulesPassed,
        ];
    }
}
