<?php

namespace App\Filament\App\Resources\ExpenseCategoryMappingResource\Pages;

use App\Filament\App\Resources\ExpenseCategoryMappingResource;
use Filament\Resources\Pages\ListRecords;

class ListExpenseCategoryMappings extends ListRecords
{
    protected static string $resource = ExpenseCategoryMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
