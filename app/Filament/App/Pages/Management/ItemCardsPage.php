<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Management;

use App\Domain\Menu\Intelligence\FoodClass;
use App\Domain\Menu\Intelligence\ItemProfileBuilder;
use App\Domain\Menu\Intelligence\PriceTier;
use App\Domain\Menu\Intelligence\Season;
use App\Domain\Menu\ItemRole;
use App\Jobs\BuildMenuIntelligence;
use App\Models\Menu\CategoryProfile;
use App\Models\Menu\ItemProfile;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/** Lets the owner review each item's card and correct who it serves, its price tier and its season. */
class ItemCardsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $routePath = 'management/item-cards';

    protected static ?string $title = 'بطاقات الأصناف';

    protected static ?string $navigationLabel = 'بطاقات الأصناف';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 22;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && $user->can('ai.manage');
    }

    public function mount(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('rebuild')
                ->label('إعادة بناء البيانات')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('سيتم إعادة حساب بطاقات الأصناف والترشيحات والكومبوهات. القيم اللي حددتها بنفسك مش هتتغير.')
                ->action(function (): void {
                    $this->rebuild();
                }),
        ];
    }

    public function rebuild(): void
    {
        BuildMenuIntelligence::dispatch($this->organizationId());

        Notification::make()
            ->title('تم جدولة إعادة البناء')
            ->body('سيتم تحديث بيانات المنيو خلال دقائق.')
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('بطاقات الأصناف')
            ->description('عدد الأفراد والفئة والموسم اللي تحددهم بنفسك بيتعتمدوا نهائياً ومش بيغيرهم البناء الليلي.')
            ->records(fn (?string $search, ?array $filters, int|string $page, int|string|null $recordsPerPage): LengthAwarePaginator => $this->paginatedItems($search, $filters ?? [], (int) $page, (int) ($recordsPerPage ?: 25)))
            ->columns([
                TextColumn::make('name')
                    ->label('الصنف')
                    ->wrap(),
                TextColumn::make('role_label')
                    ->label('الدور')
                    ->placeholder('غير مصنف'),
                TextColumn::make('category')
                    ->label('التصنيف')
                    ->placeholder('—'),
                SelectColumn::make('serves_n')
                    ->label('عدد الأفراد')
                    ->options(array_combine(range(1, ItemProfileBuilder::MAX_SERVES), array_map('strval', range(1, ItemProfileBuilder::MAX_SERVES))))
                    ->selectablePlaceholder(false)
                    ->disabled(fn (): bool => ! static::canAccess())
                    ->updateStateUsing(function (array $record, mixed $state): void {
                        $this->setServes((string) $record['item_key'], $state);
                    }),
                SelectColumn::make('price_tier')
                    ->label('الفئة')
                    ->options(self::enumOptions(PriceTier::cases()))
                    ->selectablePlaceholder(false)
                    ->disabled(fn (): bool => ! static::canAccess())
                    ->updateStateUsing(function (array $record, mixed $state): void {
                        $tier = is_string($state) ? PriceTier::tryFrom($state) : null;
                        if ($tier !== null) {
                            $this->saveOwnerField((string) $record['item_key'], ['price_tier' => $tier->value, 'price_tier_source' => 'owner']);
                        }
                    }),
                SelectColumn::make('season')
                    ->label('الموسم')
                    ->options(self::enumOptions(Season::cases()))
                    ->selectablePlaceholder(false)
                    ->disabled(fn (): bool => ! static::canAccess())
                    ->updateStateUsing(function (array $record, mixed $state): void {
                        $season = is_string($state) ? Season::tryFrom($state) : null;
                        if ($season !== null) {
                            $this->saveOwnerField((string) $record['item_key'], ['season' => $season->value, 'season_source' => 'owner']);
                        }
                    }),
                TextColumn::make('contains_label')
                    ->label('المكونات')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('list_price')
                    ->label('سعر البيع')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('unit_cost')
                    ->label('التكلفة')
                    ->numeric(decimalPlaces: 2),
                IconColumn::make('has_recipe')
                    ->label('له وصفة')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('price_tier')
                    ->label('الفئة')
                    ->options(self::enumOptions(PriceTier::cases())),
                SelectFilter::make('role')
                    ->label('الدور')
                    ->options(self::enumOptions(ItemRole::cases())),
            ])
            ->searchable()
            ->paginated([25, 50, 100])
            ->emptyStateHeading('لا توجد بطاقات أصناف')
            ->emptyStateDescription('بتتجهز البطاقات في البناء الليلي، أو اضغط «إعادة بناء البيانات».');
    }

    /** Owner-set serving count; the price tier follows it unless the owner pinned the tier too. */
    private function setServes(string $itemKey, mixed $state): void
    {
        $servesN = filter_var($state, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => ItemProfileBuilder::MAX_SERVES]]);
        if ($servesN === false) {
            return;
        }

        $profile = $this->profile($itemKey);
        if ($profile === null) {
            return;
        }

        $profile->serves_n = $servesN;
        $profile->serves_source = 'owner';
        if ($profile->price_tier_source !== 'owner') {
            $profile->price_tier = PriceTier::forServes($servesN)->value;
        }
        $profile->save();

        Notification::make()->title('تم الحفظ')->success()->send();
    }

    /** @param  array<string, string>  $values */
    private function saveOwnerField(string $itemKey, array $values): void
    {
        $profile = $this->profile($itemKey);
        if ($profile === null) {
            return;
        }

        $profile->fill($values)->save();

        Notification::make()->title('تم الحفظ')->success()->send();
    }

    private function profile(string $itemKey): ?ItemProfile
    {
        return ItemProfile::query()
            ->where('organization_id', $this->organizationId())
            ->where('item_key', $itemKey)
            ->first();
    }

    /**
     * @param  array<PriceTier|Season|ItemRole>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    private function organizationId(): int
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || $user->organization_id === null || ! $user->can('ai.manage')) {
            abort(403);
        }

        return (int) $user->organization_id;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<string, array<string, mixed>>
     */
    private function paginatedItems(?string $search, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $items = $this->items();

        $tier = $filters['price_tier']['value'] ?? null;
        if (is_string($tier) && $tier !== '') {
            $items = array_filter($items, fn (array $item): bool => $item['price_tier'] === $tier);
        }

        $role = $filters['role']['value'] ?? null;
        if (is_string($role) && $role !== '') {
            $items = array_filter($items, fn (array $item): bool => $item['role'] === $role);
        }

        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $items = array_filter($items, fn (array $item): bool => str_contains(mb_strtolower($item['name']), $needle));
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return new LengthAwarePaginator(array_slice($items, ($page - 1) * $perPage, $perPage, true), count($items), $perPage, $page);
    }

    /**
     * One row per item profile of the organization, by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function items(): array
    {
        $orgId = $this->organizationId();

        $categories = CategoryProfile::query()
            ->where('organization_id', $orgId)
            ->pluck('name', 'category_key');

        $profiles = ItemProfile::query()
            ->where('organization_id', $orgId)
            ->orderBy('name')
            ->orderBy('item_key')
            ->get();

        $items = [];
        foreach ($profiles as $profile) {
            $items[$profile->item_key] = [
                'item_key' => $profile->item_key,
                'name' => $profile->name,
                'role' => $profile->role,
                'role_label' => $profile->role !== null ? ItemRole::tryFrom($profile->role)?->label() : null,
                'category' => $profile->category_key !== null ? (string) ($categories[$profile->category_key] ?? $profile->category_key) : null,
                'serves_n' => (string) $profile->serves_n,
                'price_tier' => $profile->price_tier,
                'season' => $profile->season,
                'contains_label' => self::containsLabel($profile->contains),
                'list_price' => $profile->list_price,
                'unit_cost' => $profile->unit_cost,
                'has_recipe' => $profile->has_recipe,
            ];
        }

        return $items;
    }

    /**
     * «رز ×1، سلطة ×0.27», largest quantity first.
     *
     * @param  array<string, float>|null  $contains
     */
    private static function containsLabel(?array $contains): ?string
    {
        if ($contains === null || $contains === []) {
            return null;
        }

        arsort($contains);
        $parts = [];
        foreach ($contains as $class => $qty) {
            $label = FoodClass::tryFrom((string) $class)?->label() ?? (string) $class;
            $parts[] = $label.' ×'.rtrim(rtrim(number_format((float) $qty, 2, '.', ''), '0'), '.');
        }

        return implode('، ', $parts);
    }
}
