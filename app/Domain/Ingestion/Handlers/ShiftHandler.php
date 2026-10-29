<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\WhShift;

class ShiftHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'shift';
    }

    protected function modelClass(): string
    {
        return WhShift::class;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        return [
            'business_date' => $data['business_date'] ?? null,
            'user_uuid' => $data['user_uuid'] ?? null,
            'start_at' => $this->parseTimestamp($data['start_at'] ?? null),
            'end_at' => $this->parseTimestamp($data['end_at'] ?? null),
            'start_cash' => (string) ($data['start_cash'] ?? '0.00'),
            'end_cash' => isset($data['end_cash']) ? (string) $data['end_cash'] : null,
            'real_cash' => isset($data['real_cash']) ? (string) $data['real_cash'] : null,
            'losses_amount' => isset($data['losses_amount']) ? (string) $data['losses_amount'] : null,
            'has_deficit' => (bool) ($data['has_deficit'] ?? false),
            'closed' => (bool) ($data['closed'] ?? false),
        ];
    }
}
