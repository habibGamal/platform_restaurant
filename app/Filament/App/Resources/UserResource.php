<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\UserResource\Pages;
use App\Models\User;
use BackedEnum;
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
use Illuminate\Validation\ValidationException;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'المستخدمون';

    protected static ?string $navigationLabel = 'المستخدمون';

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    public static function getEloquentQuery(): Builder
    {
        $orgId = auth()->user()?->organization_id;

        return parent::getEloquentQuery()
            ->where('organization_id', $orgId);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->label('كلمة المرور')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),
                Forms\Components\Select::make('role')
                    ->label('الدور')
                    ->options([
                        'org_owner' => 'مالك',
                        'org_analyst' => 'محلل',
                        'branch_manager' => 'مدير فرع',
                    ])
                    ->required()
                    ->live()
                    ->afterStateHydrated(function ($component, ?User $record): void {
                        if ($record) {
                            $component->state($record->getRoleNames()->first());
                        }
                    })
                    ->rules([
                        function (Get $get, ?User $record) {
                            return function (string $attribute, $value, \Closure $fail) use ($record) {
                                if ($record && $record->id === auth()->id() && $record->hasRole('org_owner')) {
                                    if ($value !== 'org_owner') {
                                        $otherOwners = User::where('organization_id', $record->organization_id)
                                            ->where('id', '!=', $record->id)
                                            ->role('org_owner')
                                            ->count();

                                        if ($otherOwners === 0) {
                                            $fail('لا يمكنك التنازل عن دور المالك لأنك المالك الوحيد للمنظمة.');
                                        }
                                    }
                                }
                            };
                        },
                    ]),
                Forms\Components\Select::make('branches')
                    ->label('الفروع المصرح بها')
                    ->relationship(
                        'branches',
                        'name',
                        fn (Builder $query) => $query->where('organization_id', auth()->user()?->organization_id)
                    )
                    ->multiple()
                    ->preload()
                    ->visible(fn (Get $get): bool => $get('role') === 'branch_manager'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('الدور')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'org_owner' => 'مالك',
                        'org_analyst' => 'محلل',
                        'branch_manager' => 'مدير فرع',
                        default => (string) $state,
                    })
                    ->color(fn ($state): string => match ($state) {
                        'org_owner' => 'primary',
                        'org_analyst' => 'info',
                        'branch_manager' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('branches.name')
                    ->label('الفروع')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->before(function (User $record, DeleteAction $action): void {
                        if ($record->id === auth()->id() && $record->hasRole('org_owner')) {
                            $otherOwners = User::where('organization_id', $record->organization_id)
                                ->where('id', '!=', $record->id)
                                ->role('org_owner')
                                ->count();

                            if ($otherOwners === 0) {
                                Notification::make()
                                    ->title('تعذر الحذف')
                                    ->body('لا يمكنك حذف حسابك لأنك المالك الوحيد للمنظمة.')
                                    ->danger()
                                    ->send();

                                throw ValidationException::withMessages([
                                    'user' => 'لا يمكنك حذف حسابك لأنك المالك الوحيد للمنظمة.',
                                ]);
                            }
                        }
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
