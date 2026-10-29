<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\CalendarEventResource\Pages;
use App\Models\CalendarEvent;
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

class CalendarEventResource extends Resource
{
    protected static ?string $model = CalendarEvent::class;

    protected static ?string $modelLabel = 'حدث تقويم';

    protected static ?string $pluralModelLabel = 'أحداث التقويم';

    protected static ?string $navigationLabel = 'أحداث التقويم';

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    public static function getEloquentQuery(): Builder
    {
        $orgId = auth()->user()?->organization_id;

        return parent::getEloquentQuery()
            ->where(fn (Builder $query) => $query->where('organization_id', $orgId)->orWhereNull('organization_id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('اسم الحدث')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('kind')
                    ->label('نوع الحدث')
                    ->options([
                        'ramadan' => 'رمضان',
                        'eid' => 'عيد',
                        'holiday' => 'إجازة',
                        'promo' => 'عرض',
                    ])
                    ->required(),
                Forms\Components\DatePicker::make('date_from')
                    ->label('من تاريخ')
                    ->required(),
                Forms\Components\DatePicker::make('date_to')
                    ->label('إلى تاريخ')
                    ->required()
                    ->afterOrEqual('date_from'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم الحدث')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('kind')
                    ->label('نوع الحدث')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => match ($state) {
                        'ramadan' => 'رمضان',
                        'eid' => 'عيد',
                        'holiday' => 'إجازة',
                        'promo' => 'عرض',
                        default => (string) $state,
                    })
                    ->color(fn ($state): string => match ($state) {
                        'ramadan' => 'success',
                        'eid' => 'warning',
                        'holiday' => 'info',
                        'promo' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('date_from')
                    ->label('من تاريخ')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('date_to')
                    ->label('إلى تاريخ')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('organization_id')
                    ->label('النطاق')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state === null ? 'عام (النظام)' : 'خاص بالمنظمة')
                    ->color(fn ($state): string => $state === null ? 'gray' : 'primary'),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn (CalendarEvent $record): bool => $record->organization_id !== null && (auth()->user()?->can('users.manage') ?? false)),
                DeleteAction::make()
                    ->visible(fn (CalendarEvent $record): bool => $record->organization_id !== null && (auth()->user()?->can('users.manage') ?? false)),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCalendarEvents::route('/'),
            'create' => Pages\CreateCalendarEvent::route('/create'),
            'edit' => Pages\EditCalendarEvent::route('/{record}/edit'),
        ];
    }
}
