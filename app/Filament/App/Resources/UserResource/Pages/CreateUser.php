<?php

namespace App\Filament\App\Resources\UserResource\Pages;

use App\Filament\App\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = auth()->user()?->organization_id;

        return $data;
    }

    protected function afterCreate(): void
    {
        $role = $this->data['role'] ?? null;
        $record = $this->getRecord();

        if ($role && is_string($role) && $record instanceof User) {
            $record->syncRoles([$role]);
        }
    }
}
