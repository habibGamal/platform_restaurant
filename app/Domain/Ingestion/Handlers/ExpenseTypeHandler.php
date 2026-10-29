<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\DimExpenseType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class ExpenseTypeHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'expense_type';
    }

    protected function modelClass(): string
    {
        return DimExpenseType::class;
    }

    protected function hasBusinessDate(): bool
    {
        return false;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        return [
            'name' => (string) $data['name'],
            'category' => (string) ($data['category'] ?? 'other'),
            'avg_month_rate' => isset($data['avg_month_rate']) ? (string) $data['avg_month_rate'] : null,
        ];
    }

    protected function computeDirtyDatesForDelete(Branch $branch, Model $existing): array
    {
        return $this->allDatesCurrentAndPreviousMonth($branch);
    }

    protected function computeDirtyDatesForUpsert(
        Branch $branch,
        ?string $oldBusinessDate,
        ?string $newBusinessDate,
        array $data,
        ?Model $existing,
    ): array {
        $newCategory = (string) ($data['category'] ?? 'other');
        $oldCategory = $existing?->getAttribute('category');

        if ($existing === null || $oldCategory !== $newCategory) {
            return $this->allDatesCurrentAndPreviousMonth($branch);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function allDatesCurrentAndPreviousMonth(Branch $branch): array
    {
        $tz = $branch->timezone ?: 'Africa/Cairo';
        $today = Carbon::now($tz)->startOfDay();
        $start = $today->copy()->subMonthNoOverflow()->startOfMonth();

        $dates = [];
        $current = $start->copy();
        while ($current->lte($today)) {
            $dates[] = $current->format('Y-m-d');
            $current->addDay();
        }

        return $dates;
    }
}
