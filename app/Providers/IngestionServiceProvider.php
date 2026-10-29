<?php

namespace App\Providers;

use App\Domain\Ingestion\Contracts\DocumentHandlerRegistry;
use App\Domain\Ingestion\Handlers\CategoryHandler;
use App\Domain\Ingestion\Handlers\DaySummaryHandler;
use App\Domain\Ingestion\Handlers\ExpenseHandler;
use App\Domain\Ingestion\Handlers\ExpenseTypeHandler;
use App\Domain\Ingestion\Handlers\HandlerRegistry;
use App\Domain\Ingestion\Handlers\InventoryDayHandler;
use App\Domain\Ingestion\Handlers\OrderHandler;
use App\Domain\Ingestion\Handlers\OrderReturnHandler;
use App\Domain\Ingestion\Handlers\ProductHandler;
use App\Domain\Ingestion\Handlers\PurchaseHandler;
use App\Domain\Ingestion\Handlers\RecipeHandler;
use App\Domain\Ingestion\Handlers\ShiftHandler;
use App\Domain\Ingestion\Handlers\StaffHandler;
use App\Domain\Ingestion\Handlers\StocktakingHandler;
use App\Domain\Ingestion\Handlers\SupplierHandler;
use App\Domain\Ingestion\Handlers\WasteHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/** Warehouse document handlers (task PB): bind DocumentHandlerRegistry here. */
class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentHandlerRegistry::class, function (Application $app): DocumentHandlerRegistry {
            return new HandlerRegistry([
                $app->make(OrderHandler::class),
                $app->make(OrderReturnHandler::class),
                $app->make(ShiftHandler::class),
                $app->make(ExpenseHandler::class),
                new PurchaseHandler('purchase_invoice'),
                new PurchaseHandler('purchase_return'),
                $app->make(WasteHandler::class),
                $app->make(StocktakingHandler::class),
                $app->make(InventoryDayHandler::class),
                $app->make(DaySummaryHandler::class),
                $app->make(ProductHandler::class),
                $app->make(RecipeHandler::class),
                $app->make(CategoryHandler::class),
                $app->make(SupplierHandler::class),
                $app->make(ExpenseTypeHandler::class),
                $app->make(StaffHandler::class),
            ]);
        });
    }

    public function boot(): void
    {
        //
    }
}
