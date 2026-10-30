<?php

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['role']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $user->syncRoles([$this->data['role']]);
    }
}
