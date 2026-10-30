<?php

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();
        $data['role'] = $user->getRoleNames()->first();

        return $data;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['role'], $data['organization_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $user->syncRoles([$this->data['role']]);

        if ($this->data['role'] !== 'branch_manager') {
            $user->branches()->detach();
        }
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
