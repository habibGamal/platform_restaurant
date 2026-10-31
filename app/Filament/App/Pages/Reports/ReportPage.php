<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Domain\Metrics\Period\ComparisonMode;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\PeriodResolver;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Tenancy\AccessibleBranches;
use App\Models\BranchSet;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

abstract class ReportPage extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    /**
     * Draft filters edited in the inline filter form until Apply is clicked.
     *
     * @var array<string, mixed> | null
     */
    public ?array $draftFilters = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && $user->can('analytics.view');
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function getWidgetsSchemaComponents(array $widgets, array $data = []): array
    {
        $components = parent::getWidgetsSchemaComponents($widgets, $data);
        $filterKey = hash('sha256', json_encode($this->filters, JSON_THROW_ON_ERROR));

        // Recreate lazy placeholders too, so they cannot load an older applied period.
        foreach ($components as $component) {
            if ($component instanceof LivewireComponent) {
                $component->key($component->getKey(isAbsolute: false).'-'.$filterKey);
            }
        }

        return $components;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultFilters(): array
    {
        return [
            'preset' => PeriodPreset::today->value,
            'from' => null,
            'to' => null,
            'compare' => null,
            'branches' => null,
            'set' => null,
            'restaurant' => null,
            'lfl' => false,
            'granularity' => null,
        ];
    }

    public function mount(): void
    {
        $resolvedFilters = $this->getDefaultFilters();

        if ($this->persistsFiltersInSession()) {
            $sessionKey = $this->getFiltersSessionKey();
            if (session()->has($sessionKey)) {
                $sessionData = session()->get($sessionKey);
                if (is_array($sessionData)) {
                    $resolvedFilters = array_merge($resolvedFilters, $sessionData);
                }
            }
        }

        $query = request()->query();
        if (isset($query['filters']) && is_array($query['filters'])) {
            $query = array_merge($query, $query['filters']);
        }

        $filterKeys = ['preset', 'from', 'to', 'compare', 'branches', 'set', 'restaurant', 'lfl', 'granularity', 'channel', 'exclude_events'];

        foreach ($filterKeys as $key) {
            if (array_key_exists($key, $query)) {
                $val = $query[$key];

                if ($key === 'branches') {
                    if (is_string($val)) {
                        $parts = array_filter(
                            array_map('trim', explode(',', $val)),
                            fn (string $p): bool => $p !== '' && is_numeric($p)
                        );
                        $resolvedFilters[$key] = array_values(array_map('intval', $parts));
                    } elseif (is_array($val)) {
                        $resolvedFilters[$key] = array_values(array_map('intval', $val));
                    }
                } elseif ($key === 'lfl' || $key === 'exclude_events') {
                    $resolvedFilters[$key] = ($val === '1' || $val === 1 || $val === true || $val === 'true');
                } elseif ($key === 'set' || $key === 'restaurant') {
                    $resolvedFilters[$key] = is_numeric($val) ? (int) $val : $val;
                } else {
                    $resolvedFilters[$key] = $val;
                }
            }
        }

        $this->filters = $resolvedFilters;
        $this->draftFilters = $resolvedFilters;

        $this->getFiltersForm()->fill($this->draftFilters);

        if ($this->persistsFiltersInSession()) {
            session()->put(
                $this->getFiltersSessionKey(),
                $this->filters,
            );
        }
    }

    public function applyFilters(): void
    {
        $committed = $this->getFiltersForm()->getState();
        if (isset($committed['from']) && is_string($committed['from'])) {
            $committed['from'] = substr($committed['from'], 0, 10);
        }
        if (isset($committed['to']) && is_string($committed['to'])) {
            $committed['to'] = substr($committed['to'], 0, 10);
        }

        $this->draftFilters = $committed;
        $this->filters = $committed;

        if ($this->persistsFiltersInSession()) {
            session()->put(
                $this->getFiltersSessionKey(),
                $this->filters,
            );
        }

        $this->resetErrorBag();
    }

    public function resetFilters(): void
    {
        $defaults = $this->getDefaultFilters();

        $this->draftFilters = $defaults;
        $this->filters = $defaults;

        $this->getFiltersForm()->fill($this->draftFilters);

        if ($this->persistsFiltersInSession()) {
            session()->put(
                $this->getFiltersSessionKey(),
                $this->filters,
            );
        }

        $this->resetErrorBag();
    }

    public function applyAction(): Action
    {
        return Action::make('apply')
            ->label('تطبيق الفلاتر')
            ->icon(Heroicon::OutlinedFunnel)
            ->color('primary')
            ->action(fn () => $this->applyFilters());
    }

    public function resetAction(): Action
    {
        return Action::make('reset')
            ->label('إعادة ضبط')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(fn () => $this->resetFilters());
    }

    public function getFiltersFormContentComponent(): Component
    {
        return Section::make('الفلاتر')
            ->icon(Heroicon::OutlinedFunnel)
            ->schema([
                EmbeddedSchema::make('filtersForm'),
            ])
            ->footerActions([
                $this->applyAction(),
                $this->resetAction(),
            ]);
    }

    public function reportFilters(): ReportFilters
    {
        $rawFilters = $this->filters ?? [];

        if (isset($rawFilters['from']) && is_string($rawFilters['from'])) {
            $rawFilters['from'] = substr($rawFilters['from'], 0, 10);
        }

        if (isset($rawFilters['to']) && is_string($rawFilters['to'])) {
            $rawFilters['to'] = substr($rawFilters['to'], 0, 10);
        }

        return ReportFilters::fromArray(
            $rawFilters,
            CarbonImmutable::now('Africa/Cairo')->startOfDay(),
            now('Africa/Cairo')->format('H:i')
        );
    }

    public function getFiltersForm(): Schema
    {
        if ((! $this->isCachingSchemas) && $this->hasCachedSchema('filtersForm')) {
            return $this->getSchema('filtersForm');
        }

        $schema = $this->makeSchema()
            ->columns([
                'md' => 2,
                'xl' => 3,
                '2xl' => 4,
            ])
            ->extraAttributes(['wire:partial' => 'table-filters-form'])
            ->statePath('draftFilters');

        return $this->filtersForm($schema);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('preset')
                    ->label('الفترة')
                    ->options(collect(PeriodPreset::cases())->mapWithKeys(fn (PeriodPreset $case): array => [$case->value => $case->label()]))
                    ->default(fn (): string => $this->getDefaultFilters()['preset'] ?? PeriodPreset::today->value)
                    ->live(),

                DatePicker::make('from')
                    ->label('من تاريخ')
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('preset') === PeriodPreset::custom->value)
                    ->beforeOrEqual('to')
                    ->visible(fn (Get $get): bool => $get('preset') === PeriodPreset::custom->value),

                DatePicker::make('to')
                    ->label('إلى تاريخ')
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('preset') === PeriodPreset::custom->value)
                    ->afterOrEqual('from')
                    ->visible(fn (Get $get): bool => $get('preset') === PeriodPreset::custom->value),

                Select::make('compare')
                    ->label('المقارنة')
                    ->options(collect(ComparisonMode::cases())->mapWithKeys(fn (ComparisonMode $case): array => [$case->value => $case->label()]))
                    ->placeholder(function (Get $get): string {
                        $presetVal = (string) ($get('preset') ?? PeriodPreset::today->value);
                        $preset = PeriodPreset::tryFrom($presetVal) ?? PeriodPreset::today;

                        return PeriodResolver::defaultComparison($preset)->label();
                    })
                    ->nullable(),

                Select::make('branches')
                    ->label('الفروع')
                    ->multiple()
                    ->searchable()
                    ->options(function (): array {
                        $user = auth()->user();
                        if (! $user) {
                            return [];
                        }

                        return AccessibleBranches::query($user)->pluck('name', 'id')->toArray();
                    })
                    ->hidden(function (): bool {
                        $user = auth()->user();
                        if (! $user) {
                            return true;
                        }

                        return count(AccessibleBranches::ids($user)) <= 1;
                    }),

                Select::make('set')
                    ->label('مجموعة الفروع')
                    ->options(function (): array {
                        $user = auth()->user();
                        if (! $user || ! $user->organization_id) {
                            return [];
                        }

                        return BranchSet::query()
                            ->where('organization_id', $user->organization_id)
                            ->where(function ($q) use ($user): void {
                                $q->whereNull('user_id')->orWhere('user_id', $user->id);
                            })
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->placeholder('كل الفروع المتاحة')
                    ->nullable(),

                Select::make('restaurant')
                    ->label('المطعم / العلامة')
                    ->options(function (): array {
                        $user = auth()->user();
                        if (! $user || ! $user->organization_id) {
                            return [];
                        }

                        return Restaurant::query()
                            ->where('organization_id', $user->organization_id)
                            ->pluck('name', 'id')
                            ->toArray();
                    })
                    ->placeholder('كل المطاعم')
                    ->nullable(),

                Toggle::make('lfl')
                    ->label('مقارنة نفس الفروع')
                    ->default(fn (): bool => (bool) ($this->getDefaultFilters()['lfl'] ?? false)),

                Select::make('granularity')
                    ->label('التجميع الزمني')
                    ->options(collect(Granularity::cases())->mapWithKeys(fn (Granularity $case): array => [$case->value => $case->label()]))
                    ->placeholder('تلقائي')
                    ->nullable(),
            ]);
    }
}
