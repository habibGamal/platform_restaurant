<?php

namespace App\Filament\App\Resources;

use App\Domain\Tenancy\AccessibleBranches;
use App\Domain\Tenancy\PairingCodeService;
use App\Enums\BranchStatus;
use App\Filament\App\Resources\BranchResource\Pages;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $modelLabel = 'فرع';

    protected static ?string $pluralModelLabel = 'الفروع';

    protected static ?string $navigationLabel = 'الفروع';

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('organization_id', $user->organization_id)
            ->whereIn('id', AccessibleBranches::ids($user));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('اسم الفرع')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('restaurant_id')
                    ->label('المطعم')
                    ->relationship(
                        'restaurant',
                        'name',
                        fn (Builder $query) => $query->where('organization_id', auth()->user()?->organization_id)
                    )
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('timezone')
                    ->label('المنطقة الزمنية')
                    ->default('Africa/Cairo')
                    ->required(),
                Forms\Components\DatePicker::make('opened_on')
                    ->label('تاريخ الافتتاح'),
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
                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn ($state): string => match ($state instanceof BranchStatus ? $state : BranchStatus::tryFrom((string) $state)) {
                        BranchStatus::ACTIVE => 'success',
                        BranchStatus::PENDING => 'warning',
                        BranchStatus::SUSPENDED => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state): string => match ($state instanceof BranchStatus ? $state : BranchStatus::tryFrom((string) $state)) {
                        BranchStatus::ACTIVE => BranchStatus::ACTIVE->label(),
                        BranchStatus::PENDING => BranchStatus::PENDING->label(),
                        BranchStatus::SUSPENDED => BranchStatus::SUSPENDED->label(),
                        default => (string) $state,
                    }),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('آخر ظهور')
                    ->since()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pos_version')
                    ->label('إصدار نقطة البيع'),
            ])
            ->actions([
                Action::make('generatePairingCode')
                    ->label('إنشاء رمز ربط')
                    ->icon('heroicon-o-key')
                    ->visible(fn (): bool => auth()->user()?->can('branches.manage') ?? false)
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
                    ->visible(fn (): bool => auth()->user()?->can('branches.manage') ?? false)
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
                DeleteBulkAction::make()->authorizeIndividualRecords(),
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
