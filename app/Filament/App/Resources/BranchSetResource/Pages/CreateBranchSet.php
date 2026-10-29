<?php

namespace App\Filament\App\Resources\BranchSetResource\Pages;

use App\Filament\App\Resources\BranchSetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBranchSet extends CreateRecord
{
    protected static string $resource = BranchSetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = auth()->user()?->organization_id;

        if (auth()->user()?->can('users.manage') && ! empty($data['is_org_wide'])) {
            $data['user_id'] = null;
        } else {
            $data['user_id'] = auth()->id();
        }

        unset($data['is_org_wide']);

        return $data;
    }
}
