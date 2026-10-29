<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\WhExpense;

class ExpenseHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'expense';
    }

    protected function modelClass(): string
    {
        return WhExpense::class;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        return [
            'shift_uuid' => $data['shift_uuid'] ?? null,
            'business_date' => $data['business_date'] ?? null,
            'expense_type_uuid' => $data['expense_type_uuid'] ?? null,
            'amount' => (string) ($data['amount'] ?? '0.00'),
            'notes' => $data['notes'] ?? null,
        ];
    }
}
