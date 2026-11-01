<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Evals;

final readonly class EvalReport
{
    public const GROUNDING_THRESHOLD = 98.0;

    public const PERMISSION_THRESHOLD = 100.0;

    public const TOOLS_THRESHOLD = 90.0;

    /**
     * @param  list<EvalCaseResult>  $cases
     * @param  array<string, array{
     *     total: int,
     *     tools_passed: int,
     *     grounding_passed: int,
     *     permission_passed: int,
     *     passed: int,
     *     tools_pct: float,
     *     grounding_pct: float,
     *     permission_pct: float,
     *     overall_pct: float
     * }>  $categorySummaries
     */
    public function __construct(
        public ?string $model,
        public ?string $provider,
        public array $cases,
        public array $categorySummaries,
        public float $toolsAccuracy,
        public float $groundingAccuracy,
        public float $permissionAccuracy,
        public float $overallAccuracy,
        public bool $passedCertification,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'provider' => $this->provider,
            'total_cases' => count($this->cases),
            'scores' => [
                'tools_accuracy' => $this->toolsAccuracy,
                'grounding_accuracy' => $this->groundingAccuracy,
                'permission_accuracy' => $this->permissionAccuracy,
                'overall_accuracy' => $this->overallAccuracy,
            ],
            'thresholds' => [
                'tools' => self::TOOLS_THRESHOLD,
                'grounding' => self::GROUNDING_THRESHOLD,
                'permission' => self::PERMISSION_THRESHOLD,
            ],
            'passed_certification' => $this->passedCertification,
            'categories' => $this->categorySummaries,
            'cases' => array_map(fn (EvalCaseResult $c) => $c->toArray(), $this->cases),
        ];
    }

    public function toJson(int $options = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE): string
    {
        return (string) json_encode($this->toArray(), $options);
    }
}
