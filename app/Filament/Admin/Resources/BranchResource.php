<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Tenancy\PairingCodeService;
use App\Domain\Tenancy\Scopes\OrganizationScope;
use App\Enums\BranchStatus;
use App\Filament\Admin\Resources\BranchResource\Pages;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $modelLabel = 'فرع';

    protected static ?string $pluralModelLabel = 'الفروع';

    protected static ?string $navigationLabel = 'الفروع';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(OrganizationScope::class);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('organization_id')
                    ->label('المنظمة')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),
                Forms\Components\Select::make('restaurant_id')
                    ->label('المطعم')
                    ->relationship(
                        'restaurant',
                        'name',
                        fn (Builder $query, Get $get) => $query->when(
                            $get('organization_id'),
                            fn ($q, $orgId) => $q->where('organization_id', $orgId)
                        )
                    )
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('name')
                    ->label('اسم الفرع')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('timezone')
                    ->label('المنطقة الزمنية')
                    ->default('Africa/Cairo')
                    ->required(),
                Forms\Components\DatePicker::make('opened_on')
                    ->label('تاريخ الافتتاح'),
                Forms\Components\Select::make('status')
                    ->label('الحالة')
                    ->options([
                        'pending' => 'قيد الانتظار',
                        'active' => 'نشط',
                        'suspended' => 'معلق',
                    ])
                    ->default('pending')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم الفرع')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('المطعم')
                    ->sortable(),
                Tables\Columns\TextColumn::make('organization.name')
                    ->label('المنظمة')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof BranchStatus ? $state->label() : ($state === 'active' ? 'نشط' : ($state === 'suspended' ? 'معلق' : 'قيد الانتظار'))),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('آخر ظهور')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pos_version')
                    ->label('إصدار نقطة البيع'),
            ])
            ->actions([
                Action::make('generatePairingCode')
                    ->label('إنشاء رمز ربط')
                    ->icon('heroicon-o-key')
                    ->action(function (Branch $record): void {
                        $code = PairingCodeService::generate($record, auth()->user());

                        Notification::make()
                            ->title('تم إنشاء رمز الربط بنجاح')
                            ->body("رمز الربط للفرع {$record->name}: {$code} (صالح لمدة 15 دقيقة)")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
                Action::make('unlink')
                    ->label('إلغاء الربط')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Branch $record): void {
                        $record->revokeTokens();
                        $record->update(['status' => BranchStatus::PENDING]);

                        Notification::make()
                            ->title('تم إلغاء ربط الفرع بنجاح')
                            ->success()
                            ->send();
                    }),
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
            'index' => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }
}
