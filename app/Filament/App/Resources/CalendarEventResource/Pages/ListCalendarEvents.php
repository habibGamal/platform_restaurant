<?php

namespace App\Filament\App\Resources\CalendarEventResource\Pages;

use App\Filament\App\Resources\CalendarEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCalendarEvents extends ListRecords
{
    protected static string $resource = CalendarEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn (): bool => auth()->user()?->can('users.manage') ?? false),
        ];
    }
}
