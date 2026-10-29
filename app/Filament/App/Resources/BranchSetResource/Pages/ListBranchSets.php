<?php

namespace App\Filament\App\Resources\BranchSetResource\Pages;

use App\Filament\App\Resources\BranchSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBranchSets extends ListRecords
{
    protected static string $resource = BranchSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
