<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Tenancy\Scopes\OrganizationScope;
use App\Filament\Admin\Resources\UserResource\Pages;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'المستخدمون';

    protected static ?string $navigationLabel = 'المستخدمون';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    /** @var array<string, string> */
    private const ROLES = [
        'org_owner' => 'مالك',
        'org_analyst' => 'محلل',
        'branch_manager' => 'مدير فرع',
    ];

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if (! auth()->user()?->hasRole('operator')) {
            return Response::deny();
        }

        $isTenantUser = $record instanceof User
            && $record->organization_id !== null
            && ! $record->hasRole('operator');

        $allowed = match ($action) {
            'viewAny', 'create' => true,
            'view', 'update' => $isTenantUser,
            'delete' => $isTenantUser && ! static::isLastOwner($record),
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function isLastOwner(User $user): bool
    {
        return $user->hasRole('org_owner')
            && ! User::where('organization_id', $user->organization_id)
                ->whereKeyNot($user->id)
                ->role('org_owner')
                ->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('organization_id')
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('name', 'operator'))
            ->with(['organization', 'roles', 'branches']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')
                ->label('المنظمة')
                ->relationship('organization', 'name')
                ->searchable()
                ->preload()
                ->required()
                ->disabledOn('edit')
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('branches', []))
                ->helperText('يُنشأ الحساب داخل المنظمة المحددة.'),
            TextInput::make('name')->label('الاسم')->required()->maxLength(255),
            TextInput::make('email')
                ->label('البريد الإلكتروني')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
            TextInput::make('password')
                ->label('كلمة المرور')->password()->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->minLength(8)->maxLength(255)
                ->helperText('اتركها فارغة عند التعديل للاحتفاظ بكلمة المرور الحالية.'),
            Select::make('role')
                ->label('الدور')->options(self::ROLES)->default('org_owner')->required()->live()
                ->rules([
                    Rule::in(array_keys(self::ROLES)),
                    fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($record && $value !== 'org_owner' && static::isLastOwner($record)) {
                            $fail('أضف مالكاً آخر للمنظمة قبل تغيير دور المالك الوحيد.');
                        }
                    },
                ]),
            Select::make('branches')
                ->label('الفروع المصرح بها')
                ->relationship('branches', 'name', fn (Builder $query, Get $get, ?User $record) => $query
                    ->withoutGlobalScope(OrganizationScope::class)
                    ->where('organization_id', $record ? $record->organization_id : $get('organization_id')))
                ->multiple()->searchable()->preload()
                ->visible(fn (Get $get): bool => $get('role') === 'branch_manager')
                ->nestedRecursiveRule(fn (Get $get, ?User $record) => Rule::exists('branches', 'id')
                    ->where('organization_id', $record ? $record->organization_id : $get('organization_id'))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('الاسم')->searchable()->sortable(),
            TextColumn::make('email')->label('البريد الإلكتروني')->searchable()->sortable(),
            TextColumn::make('organization.name')->label('المنظمة')->searchable()->sortable(),
            TextColumn::make('roles.name')->label('الدور')->badge()
                ->formatStateUsing(fn (string $state): string => self::ROLES[$state] ?? $state),
            TextColumn::make('branches.name')->label('الفروع')->badge(),
        ])->filters([
            SelectFilter::make('organization')->label('المنظمة')->relationship('organization', 'name')->searchable()->preload(),
        ])->recordActions([
            EditAction::make(),
            DeleteAction::make(),
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
