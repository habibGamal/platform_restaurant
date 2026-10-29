<?php

namespace App\Filament\App\Resources;

use App\Domain\Tenancy\AccessibleBranches;
use App\Filament\App\Resources\BranchSetResource\Pages;
use App\Models\BranchSet;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BranchSetResource extends Resource
{
    protected static ?string $model = BranchSet::class;

    protected static ?string $modelLabel = 'مجموعة فروع';

    protected static ?string $pluralModelLabel = 'مجموعات الفروع';

    protected static ?string $navigationLabel = 'مجموعات الفروع';

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('organization_id', $user->organization_id)
            ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', $user->id));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('اسم المجموعة')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('branches')
                    ->label('الفروع')
                    ->relationship(
                        'branches',
                        'name',
                        fn (Builder $query) => $query->whereIn('id', AccessibleBranches::ids(auth()->user()))
                    )
                    ->multiple()
                    ->preload()
                    ->required(),
                Forms\Components\Toggle::make('is_org_wide')
                    ->label('على مستوى المنظمة')
                    ->helperText('إتاحة المجموعة لكافة مستخدمي المنظمة')
                    ->visible(fn (): bool => auth()->user()?->can('users.manage') ?? false)
                    ->afterStateHydrated(function ($component, ?BranchSet $record): void {
                        if ($record) {
                            $component->state($record->user_id === null);
                        }
                    })
                    ->dehydrated(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم المجموعة')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('user_id')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state === null ? 'على مستوى المنظمة' : 'خاصة')
                    ->color(fn ($state): string => $state === null ? 'success' : 'info'),
                Tables\Columns\TextColumn::make('branches_count')
                    ->counts('branches')
                    ->label('عدد الفروع'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBranchSets::route('/'),
            'create' => Pages\CreateBranchSet::route('/create'),
            'edit' => Pages\EditBranchSet::route('/{record}/edit'),
        ];
    }
}
