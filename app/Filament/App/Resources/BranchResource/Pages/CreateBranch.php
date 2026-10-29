<?php

namespace App\Filament\App\Resources\BranchResource\Pages;

use App\Enums\BranchStatus;
use App\Filament\App\Resources\BranchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = auth()->user()?->organization_id;
        $data['status'] = $data['status'] ?? BranchStatus::PENDING;

        return $data;
    }
}
