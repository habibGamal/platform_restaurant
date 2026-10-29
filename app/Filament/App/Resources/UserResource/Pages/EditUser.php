<?php

namespace App\Filament\App\Resources\UserResource\Pages;

use App\Filament\App\Resources\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        $role = $this->data['role'] ?? null;
        $record = $this->getRecord();

        if ($role && is_string($role) && $record instanceof User) {
            $record->syncRoles([$role]);

            if ($role !== 'branch_manager') {
                $record->branches()->detach();
            }
        }
    }

    protected function beforeDelete(): void
    {
        $record = $this->getRecord();

        if ($record instanceof User && $record->id === auth()->id() && $record->hasRole('org_owner')) {
            $otherOwners = User::where('organization_id', $record->organization_id)
                ->where('id', '!=', $record->id)
                ->role('org_owner')
                ->count();

            if ($otherOwners === 0) {
                throw ValidationException::withMessages([
                    'user' => 'لا يمكنك حذف حسابك لأنك المالك الوحيد للمنظمة.',
                ]);
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (): void {
                    $this->beforeDelete();
                }),
        ];
    }
}
