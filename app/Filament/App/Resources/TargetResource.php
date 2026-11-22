<?php

namespace App\Filament\App\Resources;

use App\Domain\Tenancy\AccessibleBranches;
use App\Filament\App\Resources\TargetResource\Pages;
use App\Models\Target;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

class TargetResource extends Resource
{
    protected static ?string $model = Target::class;

    protected static ?string $modelLabel = 'هدف';

    protected static ?string $pluralModelLabel = 'الأهداف';

    protected static ?string $navigationLabel = 'تحديد الأهداف';

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if (! $user) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('organization_id', $user->organization_id)
            ->whereIn('branch_id', AccessibleBranches::ids($user));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('branch_id')
                    ->label('الفرع')
                    ->relationship(
                        'branch',
                        'name',
                        fn (Builder $query) => $query->where('organization_id', auth()->user()?->organization_id)
                            ->whereIn('id', AccessibleBranches::ids(auth()->user()))
                    )
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('metric')
                    ->label('المؤشر')
                    ->options([
                        'net_sales' => 'المبيعات',
                        'net_profit' => 'صافي الربح',
                        'orders' => 'عدد الطلبات',
                        'food_cost_pct' => 'نسبة تكلفة الخامات',
                    ])
                    ->required()
                    ->live(),
                Forms\Components\DatePicker::make('period_month')
                    ->label('الشهر المستهدف')
                    ->displayFormat('Y-m')
                    ->required()
                    ->mutateDehydratedStateUsing(fn ($state) => $state ? Carbon::parse($state)->startOfMonth()->format('Y-m-d') : null)
                    ->rules([
                        function (Get $get, ?Target $record) {
                            return function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                $branchId = $get('branch_id');
                                $metric = $get('metric');
                                if (! $branchId || ! $metric || ! $value) {
                                    return;
                                }

                                $month = Carbon::parse($value)->startOfMonth()->format('Y-m-d');
                                $exists = Target::query()
                                    ->where('branch_id', $branchId)
                                    ->where('metric', $metric)
                                    ->where('period_month', $month)
                                    ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                    ->exists();

                                if ($exists) {
                                    $fail('تم تحديد هدف لهذا الفرع وهذا المؤشر في هذا الشهر مسبقاً.');
                                }
                            };
                        },
                    ]),
                Forms\Components\TextInput::make('value')
                    ->label(fn (Get $get): string => $get('metric') === 'food_cost_pct' ? 'النسبة المستهدفة (%)' : 'القيمة المستهدفة')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->suffix(fn (Get $get): ?string => $get('metric') === 'food_cost_pct' ? '%' : null)
                    ->afterStateHydrated(function ($component, ?Target $record, $state): void {
                        if ($record && $record->metric === 'food_cost_pct' && $state !== null) {
                            $component->state(round((float) $state * 100, 2));
                        }
                    })
                    ->dehydrateStateUsing(function ($state, Get $get) {
                        if ($get('metric') === 'food_cost_pct' && $state !== null) {
                            return (float) $state / 100;
                        }

                        return $state;
                    }),
                Forms\Components\Select::make('distribution')
                    ->label('طريقة التوزيع')
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: 'بالتساوي: يقسم الهدف على أيام الفرع المؤهلة في الشهر. حسب أيام الأسبوع: يستخدم نمط مبيعات آخر 8 أسابيع كاملة بعد استبعاد المناسبات، ويعود للتوزيع المتساوي إذا لم تتوفر بيانات كافية.')
                    ->options([
                        'weekday_weights' => 'حسب أيام الأسبوع',
                        'flat' => 'بالتساوي',
                    ])
                    ->default('weekday_weights')
                    ->required(),
                Forms\Components\Textarea::make('notes')
                    ->label('ملاحظات')
                    ->columnSpanFull()
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('الفرع')
                    ->sortable(),
                Tables\Columns\TextColumn::make('metric')
                    ->label('المؤشر')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'net_sales' => 'المبيعات',
                        'net_profit' => 'صافي الربح',
                        'orders' => 'عدد الطلبات',
                        'food_cost_pct' => 'نسبة تكلفة الخامات',
                        default => (string) $state,
                    }),
                Tables\Columns\TextColumn::make('period_month')
                    ->label('الشهر')
                    ->date('Y-m')
                    ->sortable(),
                Tables\Columns\TextColumn::make('value')
                    ->label('القيمة المستهدفة')
                    ->formatStateUsing(function (Target $record, $state): string {
                        if ($record->metric === 'food_cost_pct') {
                            return round((float) $state * 100, 2).' %';
                        }
                        if ($record->metric === 'orders') {
                            return number_format((float) $state, 0);
                        }

                        return number_format((float) $state, 2).' ج.م';
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('distribution')
                    ->label('طريقة التوزيع')
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'weekday_weights' => 'حسب أيام الأسبوع',
                        'flat' => 'بالتساوي',
                        default => (string) $state,
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('branch_id')
                    ->label('الفرع')
                    ->relationship(
                        'branch',
                        'name',
                        fn (Builder $query) => $query->where('organization_id', auth()->user()?->organization_id)
                            ->whereIn('id', AccessibleBranches::ids(auth()->user()))
                    ),
                SelectFilter::make('metric')
                    ->label('المؤشر')
                    ->options([
                        'net_sales' => 'المبيعات',
                        'net_profit' => 'صافي الربح',
                        'orders' => 'عدد الطلبات',
                        'food_cost_pct' => 'نسبة تكلفة الخامات',
                    ]),
                Filter::make('month')
                    ->label('الشهر')
                    ->form([
                        Forms\Components\DatePicker::make('month')
                            ->label('الشهر')
                            ->displayFormat('Y-m'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['month'] ?? null,
                            fn (Builder $q, $date) => $q->where('period_month', Carbon::parse($date)->startOfMonth()->format('Y-m-d'))
                        );
                    }),
            ])
            ->actions([
                EditAction::make()
                    ->visible(function (Target $record): bool {
                        if (auth()->user()?->hasRole('org_owner')) {
                            return true;
                        }

                        $periodMonth = Carbon::parse($record->period_month)->startOfMonth();
                        $currentMonth = Carbon::now('Africa/Cairo')->startOfMonth();

                        return $periodMonth->gte($currentMonth);
                    }),
                DeleteAction::make()
                    ->visible(function (Target $record): bool {
                        if (auth()->user()?->hasRole('org_owner')) {
                            return true;
                        }

                        $periodMonth = Carbon::parse($record->period_month)->startOfMonth();
                        $currentMonth = Carbon::now('Africa/Cairo')->startOfMonth();

                        return $periodMonth->gte($currentMonth);
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTargets::route('/'),
            'create' => Pages\CreateTarget::route('/create'),
            'edit' => Pages\EditTarget::route('/{record}/edit'),
        ];
    }
}
