<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Management;

use App\Domain\Menu\Intelligence\FoodClass;
use App\Domain\Menu\Intelligence\IntelligenceClassifier;
use App\Domain\Menu\Intelligence\LabelTrust;
use App\Models\Menu\ComponentClass;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/** Lets the owner review and correct what each recipe component (raw material) is. */
class ComponentClassesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $routePath = 'management/component-classes';

    protected static ?string $title = 'تصنيف الخامات';

    protected static ?string $navigationLabel = 'تصنيف الخامات';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 23;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

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

    public function table(Table $table): Table
    {
        return $table
            ->heading('تصنيف الخامات')
            ->description('الخامات غير الموثوقة تظهر أولاً. أي تصنيف تحدده بنفسك يُعتمد نهائياً، ويظهر أثره في بطاقات الأصناف بعد البناء الجاي.')
            ->records(fn (?string $search, ?array $filters, int|string $page, int|string|null $recordsPerPage): LengthAwarePaginator => $this->paginatedComponents($search, $filters ?? [], (int) $page, (int) ($recordsPerPage ?: 25)))
            ->columns([
                TextColumn::make('name')
                    ->label('الخامة')
                    ->wrap(),
                SelectColumn::make('class')
                    ->label('النوع')
                    ->options(self::classOptions())
                    ->selectablePlaceholder(false)
                    ->disabled(fn (): bool => ! static::canAccess())
                    ->updateStateUsing(function (array $record, mixed $state): void {
                        $class = is_string($state) ? FoodClass::tryFrom($state) : null;
                        if ($class === null) {
                            return;
                        }

                        app(IntelligenceClassifier::class)->setComponentClass($this->organizationId(), (string) $record['component_key'], $class);

                        Notification::make()->title('تم حفظ التصنيف')->success()->send();
                    }),
                TextColumn::make('source_label')
                    ->label('المصدر')
                    ->badge()
                    ->color(fn (array $record): string => $record['trusted'] ? 'success' : 'warning'),
                TextColumn::make('confidence_label')
                    ->label('الثقة'),
            ])
            ->filters([
                SelectFilter::make('class')
                    ->label('النوع')
                    ->options(self::classOptions()),
                Filter::make('untrusted')
                    ->label('غير الموثوقة فقط')
                    ->toggle(),
            ])
            ->searchable()
            ->paginated([25, 50, 100])
            ->emptyStateHeading('لا توجد خامات مصنفة')
            ->emptyStateDescription('بتظهر الخامات هنا بعد أول بناء لبيانات المنيو.');
    }

    /** @return array<string, string> */
    private static function classOptions(): array
    {
        $options = [];
        foreach (FoodClass::cases() as $class) {
            $options[$class->value] = $class->label();
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
    private function paginatedComponents(?string $search, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = $this->components();

        $class = $filters['class']['value'] ?? null;
        if (is_string($class) && $class !== '') {
            $rows = array_filter($rows, fn (array $row): bool => $row['class'] === $class);
        }

        if ((bool) ($filters['untrusted']['isActive'] ?? false)) {
            $rows = array_filter($rows, fn (array $row): bool => ! $row['trusted']);
        }

        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $rows = array_filter($rows, fn (array $row): bool => str_contains(mb_strtolower($row['name']), $needle));
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage, true), count($rows), $perPage, $page);
    }

    /**
     * One row per classified component, untrusted first, then by confidence ascending.
     *
     * @return array<string, array<string, mixed>>
     */
    private function components(): array
    {
        $classes = ComponentClass::query()
            ->where('organization_id', $this->organizationId())
            ->get();

        $rows = [];
        foreach ($classes as $component) {
            $rows[$component->component_key] = [
                'component_key' => $component->component_key,
                'name' => $component->name,
                'class' => $component->class,
                'source_label' => match ($component->source) {
                    'owner' => 'حددته أنت',
                    'auto' => 'ذكاء اصطناعي',
                    'rule' => 'قاعدة الاسم',
                    default => 'غير معروف',
                },
                'confidence' => $component->confidence,
                'confidence_label' => number_format($component->confidence * 100).'%',
                'trusted' => LabelTrust::isTrusted($component->source, $component->confidence),
            ];
        }

        uasort($rows, fn (array $a, array $b): int => [$a['trusted'], $a['confidence'], $a['name']] <=> [$b['trusted'], $b['confidence'], $b['name']]);

        return $rows;
    }
}
