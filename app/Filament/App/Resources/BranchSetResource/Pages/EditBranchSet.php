<?php

namespace App\Filament\App\Resources\BranchSetResource\Pages;

use App\Filament\App\Resources\BranchSetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBranchSet extends EditRecord
{
    protected static string $resource = BranchSetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (auth()->user()?->can('users.manage') && array_key_exists('is_org_wide', $data)) {
            $data['user_id'] = ! empty($data['is_org_wide']) ? null : auth()->id();
        }

        unset($data['is_org_wide']);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
