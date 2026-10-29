<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\OrganizationResource\Pages;
use App\Models\Organization;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $modelLabel = 'منظمة';

    protected static ?string $pluralModelLabel = 'المنظمات';

    protected static ?string $navigationLabel = 'المنظمات';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('اسم المنظمة')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->label('المعرف الفريد (Slug)')
                    ->required()
                    ->unique(Organization::class, 'slug', ignoreRecord: true)
                    ->maxLength(255),
                Forms\Components\Select::make('plan')
                    ->label('الخطة')
                    ->options([
                        'basic' => 'أساسي',
                        'pro' => 'احترافي',
                    ])
                    ->default('basic')
                    ->required(),
                Forms\Components\Toggle::make('ai_enabled')
                    ->label('تفعيل الذكاء الاصطناعي')
                    ->default(false),
                Forms\Components\TextInput::make('timezone')
                    ->label('المنطقة الزمنية')
                    ->default('Africa/Cairo')
                    ->required(),
                Forms\Components\TextInput::make('currency')
                    ->label('العملة')
                    ->default('EGP')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('اسم المنظمة')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->label('المعرف الفريد')
                    ->searchable(),
                Tables\Columns\TextColumn::make('plan')
                    ->label('الخطة')
                    ->badge(),
                Tables\Columns\IconColumn::make('ai_enabled')
                    ->label('الذكاء الاصطناعي')
                    ->boolean(),
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
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }
}
