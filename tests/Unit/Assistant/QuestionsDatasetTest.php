<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use PHPUnit\Framework\TestCase;

class QuestionsDatasetTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function loadDataset(): array
    {
        $path = dirname(__DIR__, 2).'/Evals/questions.v0.php';
        $this->assertFileExists($path);

        /** @var list<array<string, mixed>> $dataset */
        $dataset = require $path;

        return $dataset;
    }

    public function test_dataset_contains_exactly_thirty_cases(): void
    {
        $dataset = $this->loadDataset();
        $this->assertCount(30, $dataset);
    }

    public function test_dataset_has_unique_case_ids(): void
    {
        $dataset = $this->loadDataset();
        $ids = array_column($dataset, 'id');

        $this->assertCount(30, $ids);
        $this->assertEquals(count($ids), count(array_unique($ids)), 'Case IDs must be unique');
    }

    public function test_dataset_cases_have_all_required_keys_and_valid_types(): void
    {
        $dataset = $this->loadDataset();
        $requiredKeys = [
            'id',
            'category',
            'persona',
            'question',
            'expected_tools',
            'expected_numbers',
            'must_refuse',
        ];

        $validCategories = ['kpis', 'comparisons', 'trends', 'branches', 'targets', 'data_health', 'permission'];
        $validPersonas = ['owner', 'manager_a'];

        foreach ($dataset as $idx => $case) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $case, "Case #{$idx} missing key '{$key}'");
            }

            $this->assertIsString($case['id'], "Case #{$idx} 'id' must be string");
            $this->assertNotEmpty($case['id'], "Case #{$idx} 'id' cannot be empty");

            $this->assertContains($case['category'], $validCategories, "Case #{$idx} invalid category: {$case['category']}");
            $this->assertContains($case['persona'], $validPersonas, "Case #{$idx} invalid persona: {$case['persona']}");

            $this->assertIsString($case['question'], "Case #{$idx} 'question' must be string");
            $this->assertNotEmpty($case['question'], "Case #{$idx} 'question' cannot be empty");

            $this->assertIsArray($case['expected_tools'], "Case #{$idx} 'expected_tools' must be array");
            $this->assertIsArray($case['expected_numbers'], "Case #{$idx} 'expected_numbers' must be array");
            $this->assertIsBool($case['must_refuse'], "Case #{$idx} 'must_refuse' must be bool");
        }
    }

    public function test_dataset_covers_required_categories(): void
    {
        $dataset = $this->loadDataset();
        $categories = array_unique(array_column($dataset, 'category'));

        $this->assertContains('kpis', $categories);
        $this->assertContains('comparisons', $categories);
        $this->assertContains('trends', $categories);
        $this->assertContains('branches', $categories);
        $this->assertContains('targets', $categories);
        $this->assertContains('data_health', $categories);
        $this->assertContains('permission', $categories);
    }
}
