<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Management;

use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Jobs\ClassifyItemRoles;
use App\Models\ItemRoleAssignment;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Lets the owner review and correct the role the assistant believes each menu item plays. */
class ItemRolesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $routePath = 'management/item-roles';

    protected static ?string $title = 'مراجعة أدوار الأصناف';

    protected static ?string $navigationLabel = 'أدوار الأصناف';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 21;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

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
            Action::make('reclassify')
                ->label('إعادة التصنيف')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('سيتم تصنيف الأصناف التي ليس لها دور موثوق. الأدوار التي حددتها بنفسك لن تتغير.')
                ->action(function (): void {
                    $this->reclassify();
                }),
        ];
    }

    public function reclassify(): void
    {
        ClassifyItemRoles::dispatch($this->organizationId());

        Notification::make()
            ->title('تم جدولة إعادة التصنيف')
            ->body('سيتم تحديث أدوار الأصناف خلال دقائق.')
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('أدوار الأصناف')
            ->description('الأصناف غير المصنفة أو منخفضة الثقة تظهر أولاً. أي دور تحدده بنفسك يُعتمد نهائياً ولا يغيره التصنيف التلقائي.')
            ->records(fn (?string $search, int|string $page, int|string|null $recordsPerPage): LengthAwarePaginator => $this->paginatedItems($search, (int) $page, (int) ($recordsPerPage ?: 25)))
            ->columns([
                TextColumn::make('name')
                    ->label('الصنف')
                    ->wrap(),
                TextColumn::make('category')
                    ->label('التصنيف')
                    ->placeholder('—'),
                SelectColumn::make('role')
                    ->label('الدور')
                    ->options(collect(ItemRole::cases())->mapWithKeys(fn (ItemRole $role): array => [$role->value => $role->label()])->all())
                    ->placeholder('غير مصنف')
                    ->selectablePlaceholder(false)
                    ->disabled(fn (): bool => ! static::canAccess())
                    ->updateStateUsing(function (array $record, mixed $state): void {
                        $role = is_string($state) ? ItemRole::tryFrom($state) : null;
                        if ($role === null) {
                            return;
                        }

                        app(ItemRoles::class)->setOwnerRole($this->organizationId(), (string) $record['item_key'], $role);

                        Notification::make()->title('تم حفظ الدور')->success()->send();
                    }),
                TextColumn::make('source_label')
                    ->label('المصدر')
                    ->badge()
                    ->color(fn (array $record): string => $record['trusted'] ? 'success' : 'warning'),
                TextColumn::make('confidence_label')
                    ->label('الثقة')
                    ->placeholder('—'),
            ])
            ->searchable()
            ->paginated([25, 50, 100])
            ->emptyStateHeading('لا توجد أصناف')
            ->emptyStateDescription('ستظهر الأصناف هنا بعد وصول بيانات المنيو من الفروع.');
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

    /** @return LengthAwarePaginator<string, array<string, mixed>> */
    private function paginatedItems(?string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $items = $this->items();

        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $items = array_filter($items, fn (array $item): bool => str_contains(mb_strtolower($item['name'].' '.($item['category'] ?? '')), $needle));
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return new LengthAwarePaginator(array_slice($items, ($page - 1) * $perPage, $perPage, true), count($items), $perPage, $page);
    }

    /**
     * One row per org-wide item key, untrusted (including unclassified) first, then by confidence ascending.
     *
     * @return array<string, array<string, mixed>>
     */
    private function items(): array
    {
        $orgId = $this->organizationId();

        $assignments = ItemRoleAssignment::query()
            ->where('organization_id', $orgId)
            ->get()
            ->keyBy('item_key');

        $products = DB::table('dim_products as p')
            ->leftJoin('dim_categories as c', function ($join): void {
                $join->on('c.branch_id', '=', 'p.branch_id')->on('c.uuid', '=', 'p.category_uuid');
            })
            ->where('p.organization_id', $orgId)
            ->whereNull('p.deleted_at')
            ->orderBy('p.id')
            ->get(['p.uuid', 'p.product_ref', 'p.name', 'c.name as category_name']);

        $items = [];
        foreach ($products as $product) {
            $key = (string) ($product->product_ref ?? $product->uuid);
            $assignment = $assignments->get($key);
            $trusted = $assignment !== null
                && ItemRoles::isTrustedRow($assignment->source, (float) $assignment->confidence, $assignment->role);

            // The latest product row names the item.
            $items[$key] = [
                'item_key' => $key,
                'name' => (string) $product->name,
                'category' => $product->category_name !== null ? (string) $product->category_name : null,
                'role' => $assignment?->role,
                'source_label' => match ($assignment?->source) {
                    'owner' => 'حددته أنت',
                    'signal' => 'مؤشرات المبيعات',
                    'auto' => 'ذكاء اصطناعي',
                    default => 'لم يُصنَّف بعد',
                },
                'confidence' => $assignment !== null ? (float) $assignment->confidence : null,
                'confidence_label' => $assignment !== null ? number_format((float) $assignment->confidence * 100).'%' : null,
                'trusted' => $trusted,
            ];
        }

        uasort($items, fn (array $a, array $b): int => [$a['trusted'], $a['confidence'] ?? -1.0, $a['name']] <=> [$b['trusted'], $b['confidence'] ?? -1.0, $b['name']]);

        return $items;
    }
}
