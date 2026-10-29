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
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

abstract class ReportPage extends BaseDashboard
{
    use HasFiltersForm;

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير';

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && $user->can('analytics.view');
    }

    public function mount(): void
    {
        $this->applyQueryStringFilters();
    }

    protected function applyQueryStringFilters(): void
    {
        $query = request()->query();
        $filterKeys = ['preset', 'from', 'to', 'compare', 'branches', 'set', 'restaurant', 'lfl', 'granularity', 'channel'];

        $appliedAny = false;
        foreach ($filterKeys as $key) {
            if (array_key_exists($key, $query)) {
                $val = $query[$key];

                if ($key === 'branches') {
                    if (is_string($val)) {
                        $parts = array_filter(
                            array_map('trim', explode(',', $val)),
                            fn (string $p): bool => $p !== '' && is_numeric($p)
                        );
                        $this->filters[$key] = array_values(array_map('intval', $parts));
                    } elseif (is_array($val)) {
                        $this->filters[$key] = array_values(array_map('intval', $val));
                    }
                } elseif ($key === 'lfl') {
                    $this->filters[$key] = ($val === '1' || $val === 1 || $val === true || $val === 'true');
                } elseif ($key === 'set' || $key === 'restaurant') {
                    $this->filters[$key] = is_numeric($val) ? (int) $val : $val;
                } else {
                    $this->filters[$key] = $val;
                }

                $appliedAny = true;
            }
        }

        if ($appliedAny) {
            $this->getFiltersForm()->fill($this->filters);

            if ($this->persistsFiltersInSession()) {
                session()->put(
                    $this->getFiltersSessionKey(),
                    $this->filters,
                );
            }
        }
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

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('preset')
                    ->label('الفترة')
                    ->options(collect(PeriodPreset::cases())->mapWithKeys(fn (PeriodPreset $case): array => [$case->value => $case->label()]))
                    ->default(PeriodPreset::today->value)
                    ->live(),

                DatePicker::make('from')
                    ->label('من تاريخ')
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('preset') === PeriodPreset::custom->value),

                DatePicker::make('to')
                    ->label('إلى تاريخ')
                    ->native(false)
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
                    ->default(false),

                Select::make('granularity')
                    ->label('التجميع الزمني')
                    ->options(collect(Granularity::cases())->mapWithKeys(fn (Granularity $case): array => [$case->value => $case->label()]))
                    ->placeholder('تلقائي')
                    ->nullable(),
            ]);
    }
}
