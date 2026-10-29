<?php

namespace Tests\Feature\Ingestion\Handlers;

use App\Domain\Ingestion\Contracts\DocumentHandler;
use App\Domain\Ingestion\Contracts\DocumentHandlerRegistry;
use App\Domain\Ingestion\Handlers\HandlerRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class HandlerRegistryTest extends TestCase
{
    private HandlerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new HandlerRegistry;
    }

    public function test_all_16_types_are_registered(): void
    {
        $expectedTypes = [
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

        $this->assertSame($expectedTypes, $this->registry->types());
        $this->assertCount(16, $this->registry->types());

        foreach ($expectedTypes as $type) {
            $handler = $this->registry->for($type);
            $this->assertInstanceOf(DocumentHandler::class, $handler);
            $this->assertSame($type, $handler->type());
        }
    }

    public function test_unknown_type_throws_invalid_argument_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown document type: invalid_type');

        $this->registry->for('invalid_type');
    }

    public function test_registry_is_bound_as_singleton_in_container(): void
    {
        $registry1 = $this->app->make(DocumentHandlerRegistry::class);
        $registry2 = $this->app->make(DocumentHandlerRegistry::class);

        $this->assertInstanceOf(HandlerRegistry::class, $registry1);
        $this->assertSame($registry1, $registry2);
        $this->assertCount(16, $registry1->types());
    }
}
