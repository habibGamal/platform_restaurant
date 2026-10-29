<?php

namespace App\Filament\App\Resources\TargetResource\Pages;

use App\Filament\App\Resources\TargetResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;

class CreateTarget extends CreateRecord
{
    protected static string $resource = TargetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = auth()->user()?->organization_id;
        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();
        $data['period_month'] = Carbon::parse($data['period_month'])->startOfMonth()->format('Y-m-d');

        return $data;
    }
}
