<?php

namespace App\Domain\Ingestion\Handlers;

use App\Domain\Ingestion\Contracts\DocumentHandler;
use App\Domain\Ingestion\Contracts\DocumentHandlerRegistry;
use InvalidArgumentException;

class HandlerRegistry implements DocumentHandlerRegistry
{
    /**
     * @var list<string>
     */
    public const SUPPORTED_TYPES = [
        'order',
        'order_return',
        'shift',
        'expense',
        'purchase_invoice',
        'purchase_return',
        'waste',
        'stocktaking',
        'inventory_day',
        'day_summary',
        'product',
        'recipe',
        'category',
        'supplier',
        'expense_type',
        'staff',
    ];

    /**
     * @var array<string, DocumentHandler>
     */
    private array $handlers = [];

    /**
     * @param  iterable<DocumentHandler>|null  $handlers
     */
    public function __construct(?iterable $handlers = null)
    {
        if ($handlers === null) {
            $handlers = $this->defaultHandlers();
        }

        foreach ($handlers as $handler) {
            $this->handlers[$handler->type()] = $handler;
        }
    }

    /**
     * @return list<DocumentHandler>
     */
    private function defaultHandlers(): array
    {
        return [
            new OrderHandler,
            new OrderReturnHandler,
            new ShiftHandler,
            new ExpenseHandler,
            new PurchaseHandler('purchase_invoice'),
            new PurchaseHandler('purchase_return'),
            new WasteHandler,
            new StocktakingHandler,
            new InventoryDayHandler,
            new DaySummaryHandler,
            new ProductHandler,
            new RecipeHandler,
            new CategoryHandler,
            new SupplierHandler,
            new ExpenseTypeHandler,
            new StaffHandler,
        ];
    }

    public function for(string $type): DocumentHandler
    {
        if (! isset($this->handlers[$type])) {
            throw new InvalidArgumentException("Unknown document type: {$type}");
        }

        return $this->handlers[$type];
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return self::SUPPORTED_TYPES;
    }
}
