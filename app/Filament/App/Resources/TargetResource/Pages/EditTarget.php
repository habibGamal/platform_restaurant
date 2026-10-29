<?php

namespace App\Filament\App\Resources\TargetResource\Pages;

use App\Filament\App\Resources\TargetResource;
use App\Models\Target;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditTarget extends EditRecord
{
    protected static string $resource = TargetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();
        if (isset($data['period_month'])) {
            $data['period_month'] = Carbon::parse($data['period_month'])->startOfMonth()->format('Y-m-d');
        }

        return $data;
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        /** @var Target $record */
        $record = $this->record;
        if (! auth()->user()?->hasRole('org_owner')) {
            $periodMonth = Carbon::parse($record->period_month)->startOfMonth();
            $currentMonth = Carbon::now('Africa/Cairo')->startOfMonth();

            if ($periodMonth->lt($currentMonth)) {
                abort(403, 'لا يمكن تعديل أهداف الأشهر السابقة.');
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(function (Target $record): bool {
                    if (auth()->user()?->hasRole('org_owner')) {
                        return true;
                    }

                    $periodMonth = Carbon::parse($record->period_month)->startOfMonth();
                    $currentMonth = Carbon::now('Africa/Cairo')->startOfMonth();

                    return $periodMonth->gte($currentMonth);
                }),
        ];
    }
}
