<?php

namespace App\Filament\App\Resources\CalendarEventResource\Pages;

use App\Filament\App\Resources\CalendarEventResource;
use App\Models\CalendarEvent;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCalendarEvent extends EditRecord
{
    protected static string $resource = CalendarEventResource::class;

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        /** @var CalendarEvent $record */
        $record = $this->record;
        if ($record->organization_id === null) {
            abort(403, 'الأحداث العامة غير قابلة للتعديل.');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(function (CalendarEvent $record): bool {
                    return $record->organization_id !== null && (auth()->user()?->can('users.manage') ?? false);
                }),
        ];
    }
}
