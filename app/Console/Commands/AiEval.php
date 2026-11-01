<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Assistant\Evals\EvalCaseResult;
use App\Domain\Assistant\Evals\EvalReport;
use App\Domain\Assistant\Evals\EvalRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiEval extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ai:eval
                            {--model= : The model name to evaluate}
                            {--provider= : The provider name to evaluate}
                            {--limit= : Limit the number of cases to evaluate}
                            {--dry-run : List dataset cases without invoking any model}
                            {--database= : Database connection to use; its database name must contain "testing"}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the AI assistant evaluation harness v0 on hand-checked fixtures';

    public function handle(EvalRunner $runner): int
    {
        $limitOption = $this->option('limit');
        $limit = ($limitOption !== null && is_numeric($limitOption)) ? (int) $limitOption : null;

        $modelOption = $this->option('model');
        $model = is_string($modelOption) ? $modelOption : null;

        $providerOption = $this->option('provider');
        $provider = is_string($providerOption) ? $providerOption : null;

        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            return $this->handleDryRun($runner, $limit);
        }

        // The runner seeds fixtures, so it may only touch a database whose name says it is a test one.
        $databaseOption = $this->option('database');
        $connection = is_string($databaseOption) && $databaseOption !== '' ? $databaseOption : null;

        if ($connection !== null) {
            config(['database.default' => $connection]);
            DB::setDefaultConnection($connection);
        }

        // Read the name from config so the check happens before any connection is opened.
        $databaseName = (string) config('database.connections.'.DB::getDefaultConnection().'.database');

        if (! str_contains(strtolower($databaseName), 'testing')) {
            $this->error("REFUSED: database [{$databaseName}] is not a test database. Evals seed fixtures and modify data.");
            $this->line('');
            $this->line('Point DB_DATABASE at a database whose name contains "testing", e.g.:');
            $this->line('  PowerShell: $env:DB_PORT=\'13317\'; $env:DB_DATABASE=\'platform_testing_d\'; php artisan ai:eval');
            $this->line('  Bash:       DB_PORT=13317 DB_DATABASE=platform_testing_d php artisan ai:eval');
            $this->line('  Or preview cases with: php artisan ai:eval --dry-run');

            return self::FAILURE;
        }

        $this->info('Starting AI Assistant Evaluation Harness v0...');
        if ($model !== null) {
            $this->line("Model: {$model}");
        }
        if ($provider !== null) {
            $this->line("Provider: {$provider}");
        }

        $report = $runner->run(
            model: $model,
            provider: $provider,
            limit: $limit,
            onProgress: function (int $current, int $total, EvalCaseResult $result): void {
                $status = $result->passed ? '<info>PASS</info>' : '<error>FAIL</error>';
                $this->line(sprintf('  [%d/%d] %s (%s): %s', $current, $total, $result->caseId, $result->category, $status));
            }
        );

        $this->renderReport($report);

        return $report->passedCertification ? self::SUCCESS : self::FAILURE;
    }

    protected function handleDryRun(EvalRunner $runner, ?int $limit): int
    {
        $cases = $runner->loadCases($limit);

        $this->info('AI Evaluation Harness v0 — Dry Run (Listing Test Cases)');
        $this->line('');

        $rows = [];
        foreach ($cases as $index => $case) {
            $expectedTools = ! empty($case['expected_tools']) ? implode(', ', $case['expected_tools']) : '-';
            $expectedNumbers = ! empty($case['expected_numbers']) ? implode(', ', $case['expected_numbers']) : '-';

            $rows[] = [
                $index + 1,
                $case['id'],
                $case['category'],
                $case['persona'],
                mb_strimwidth($case['question'], 0, 50, '...'),
                $expectedTools,
                $expectedNumbers,
                $case['must_refuse'] ? '<comment>YES</comment>' : 'NO',
            ];
        }

        $this->table(
            ['#', 'ID', 'Category', 'Persona', 'Question', 'Expected Tools', 'Expected Numbers', 'Refuse'],
            $rows
        );

        $this->info(sprintf('Total cases loaded: %d', count($cases)));

        return self::SUCCESS;
    }

    protected function renderReport(EvalReport $report): void
    {
        $this->line('');
        $this->info('=== PER-CATEGORY EVALUATION RESULTS ===');

        $rows = [];
        foreach ($report->categorySummaries as $category => $summary) {
            $rows[] = [
                $category,
                $summary['total'],
                sprintf('%d/%d (%0.1f%%)', $summary['tools_passed'], $summary['total'], $summary['tools_pct']),
                sprintf('%d/%d (%0.1f%%)', $summary['grounding_passed'], $summary['total'], $summary['grounding_pct']),
                sprintf('%d/%d (%0.1f%%)', $summary['permission_passed'], $summary['total'], $summary['permission_pct']),
                sprintf('%d/%d (%0.1f%%)', $summary['passed'], $summary['total'], $summary['overall_pct']),
            ];
        }

        $this->table(
            ['Category', 'Cases', 'Tool Selection', 'Grounding', 'Permissions', 'Overall Passed'],
            $rows
        );

        $this->line('');
        $this->info('=== EVALUATION SUMMARY & CERTIFICATION THRESHOLDS ===');

        $groundingPass = $report->groundingAccuracy >= EvalReport::GROUNDING_THRESHOLD;
        $permissionPass = $report->permissionAccuracy >= EvalReport::PERMISSION_THRESHOLD;
        $toolsPass = $report->toolsAccuracy >= EvalReport::TOOLS_THRESHOLD;

        $this->line(sprintf(
            '  Numeric Grounding:    %0.1f%% (threshold: >= %0.1f%%) -> %s',
            $report->groundingAccuracy,
            EvalReport::GROUNDING_THRESHOLD,
            $groundingPass ? '<info>PASS</info>' : '<error>FAIL</error>'
        ));

        $this->line(sprintf(
            '  Permission Adherence: %0.1f%% (threshold: == %0.1f%%) -> %s',
            $report->permissionAccuracy,
            EvalReport::PERMISSION_THRESHOLD,
            $permissionPass ? '<info>PASS</info>' : '<error>FAIL</error>'
        ));

        $this->line(sprintf(
            '  Tool Selection:       %0.1f%% (threshold: >= %0.1f%%) -> %s',
            $report->toolsAccuracy,
            EvalReport::TOOLS_THRESHOLD,
            $toolsPass ? '<info>PASS</info>' : '<error>FAIL</error>'
        ));

        $this->line(sprintf('  Overall Cases Passed: %0.1f%% (%d/%d)', $report->overallAccuracy, count(array_filter($report->cases, fn (EvalCaseResult $c) => $c->passed)), count($report->cases)));

        $this->line('');
        if ($report->passedCertification) {
            $this->info('RESULT: PASSED ALL CERTIFICATION THRESHOLDS');
        } else {
            $this->error('RESULT: FAILED TO MEET CERTIFICATION THRESHOLDS');
        }

        $this->line('');
        $this->line('=== JSON SUMMARY ===');
        $this->line($report->toJson());
    }
}
