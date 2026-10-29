<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;
use App\Models\Branch;
use App\Models\Warehouse\WhDaySummary;

class DaySummaryHandler extends AbstractDocumentHandler
{
    public function type(): string
    {
        return 'day_summary';
    }

    protected function modelClass(): string
    {
        return WhDaySummary::class;
    }

    protected function isDayClosed(): bool
    {
        return true;
    }

    protected function mapParentAttributes(Branch $branch, array $data, array $document): array
    {
        $businessDate = $data['date'] ?? $data['business_date'] ?? null;

        return [
            'business_date' => $businessDate,
            'orders_count' => (int) ($data['orders_count'] ?? 0),
            'completed_count' => (int) ($data['completed_count'] ?? 0),
            'net_total' => (string) ($data['net_total'] ?? '0.00'),
            'discount_total' => (string) ($data['discount_total'] ?? '0.00'),
            'cogs_total' => (string) ($data['cogs_total'] ?? '0.00'),
            'expenses_total' => (string) ($data['expenses_total'] ?? '0.00'),
            'waste_total' => (string) ($data['waste_total'] ?? '0.00'),
        ];
    }
}
