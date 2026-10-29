<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ExpenseCategoryMappingResource\Pages;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\Warehouse\DimExpenseType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

class ExpenseCategoryMappingResource extends Resource
{
    protected static ?string $model = DimExpenseType::class;

    protected static ?string $modelLabel = 'تصنيف مصروف';

    protected static ?string $pluralModelLabel = 'تعيين تصنيفات المصروفات';

    protected static ?string $navigationLabel = 'تصنيفات المصروفات';

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $orgId = auth()->user()?->organization_id;

        return parent::getEloquentQuery()
            ->where('dim_expense_types.organization_id', $orgId);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم بند المصروف')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('branch_id')
                    ->label('اسم الفرع')
                    ->formatStateUsing(fn ($state): string => Branch::where('id', $state)->value('name') ?? '—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('تصنيف نقطة البيع')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'labor' => 'عمالة ورواتب',
                        'rent' => 'إيجار',
                        'utilities' => 'مرافق',
                        'marketing' => 'تسويق',
                        'maintenance' => 'صيانة',
                        'raw_materials' => 'خامات',
                        'other' => 'أخرى',
                        default => (string) $state,
                    })
                    ->color('gray'),
                Tables\Columns\TextColumn::make('effective_category')
                    ->label('التصنيف الفعلي')
                    ->badge()
                    ->getStateUsing(fn (DimExpenseType $record): string => (string) ($record->getAttribute('category_override') ?: $record->getAttribute('category')))
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'labor' => 'عمالة ورواتب',
                        'rent' => 'إيجار',
                        'utilities' => 'مرافق',
                        'marketing' => 'تسويق',
                        'maintenance' => 'صيانة',
                        'raw_materials' => 'خامات',
                        'other' => 'أخرى',
                        default => (string) $state,
                    })
                    ->color('primary'),
                SelectColumn::make('category_override')
                    ->label('التصنيف المخصص')
                    ->placeholder('استخدام تصنيف نقطة البيع')
                    ->options([
                        'labor' => 'عمالة ورواتب',
                        'rent' => 'إيجار',
                        'utilities' => 'مرافق',
                        'marketing' => 'تسويق',
                        'maintenance' => 'صيانة',
                        'raw_materials' => 'خامات',
                        'other' => 'أخرى',
                    ])
                    ->beforeStateUpdated(function (DimExpenseType $record, &$state): void {
                        if ($state === '') {
                            $state = null;
                        }
                    })
                    ->afterStateUpdated(function (DimExpenseType $record, $state): void {
                        $tz = 'Africa/Cairo';
                        $startDate = Carbon::now($tz)->subMonth()->startOfMonth();
                        $endDate = Carbon::now($tz);

                        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                            RecomputeBranchDay::dispatch((int) $record->getAttribute('branch_id'), $date->format('Y-m-d'));
                        }
                    }),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExpenseCategoryMappings::route('/'),
        ];
    }
}
