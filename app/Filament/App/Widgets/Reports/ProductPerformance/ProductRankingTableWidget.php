<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ProductPerformance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProductPerformanceReport;
use App\Domain\Metrics\Reports\ProductPerformanceReport\ProductDetailSummary;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use App\Support\Format;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class ProductRankingTableWidget extends BaseReportTableWidget
{
    public string $activeTab = 'top'; // 'top' | 'bottom' | 'unsold'

    public string $rankBy = 'sales'; // 'sales' | 'qty' | 'gross_profit' | 'margin_pct'

    public int $limit = 10;

    public bool $includeLegacy = false;

    public ?string $categoryUuid = null;

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function setRankBy(string $rankBy): void
    {
        $this->rankBy = $rankBy;
    }

    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function toggleLegacy(): void
    {
        $this->includeLegacy = ! $this->includeLegacy;
    }

    public function setCategory(?string $uuid): void
    {
        $this->categoryUuid = $uuid;
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table
            ->heading(fn (): string => match ($this->activeTab) {
                'bottom' => 'الأصناف الأقل مبيعاً (تستبعد غير المباعة)',
                'unsold' => 'أصناف لم تُبَع (نشطة في قائمة الطعام)',
                default => 'الأصناف الأكثر مبيعاً (Top '.$this->limit.')',
            })
            ->description(fn (): string => 'مرتبة حسب: '.$this->rankByLabel())
            ->headerActions([
                Action::make('tab_top')
                    ->label('الأكثر مبيعاً')
                    ->color(fn (): string => $this->activeTab === 'top' ? 'primary' : 'gray')
                    ->action(fn () => $this->setTab('top')),

                Action::make('tab_bottom')
                    ->label('الأقل مبيعاً')
                    ->color(fn (): string => $this->activeTab === 'bottom' ? 'primary' : 'gray')
                    ->action(fn () => $this->setTab('bottom')),

                Action::make('tab_unsold')
                    ->label('لم يُبع')
                    ->color(fn (): string => $this->activeTab === 'unsold' ? 'primary' : 'gray')
                    ->action(fn () => $this->setTab('unsold')),

                ActionGroup::make([
                    Action::make('rank_sales')
                        ->label('صافي المبيعات')
                        ->icon($this->rankBy === 'sales' ? 'heroicon-m-check' : null)
                        ->action(fn () => $this->setRankBy('sales')),
                    Action::make('rank_qty')
                        ->label('الكمية المباعة')
                        ->icon($this->rankBy === 'qty' ? 'heroicon-m-check' : null)
                        ->action(fn () => $this->setRankBy('qty')),
                    Action::make('rank_profit')
                        ->label('مجمل الربح')
                        ->icon($this->rankBy === 'gross_profit' ? 'heroicon-m-check' : null)
                        ->action(fn () => $this->setRankBy('gross_profit')),
                    Action::make('rank_margin')
                        ->label('هامش الربح')
                        ->icon($this->rankBy === 'margin_pct' ? 'heroicon-m-check' : null)
                        ->action(fn () => $this->setRankBy('margin_pct')),
                ])
                    ->label(fn (): string => 'الترتيب: '.$this->rankByLabel())
                    ->icon('heroicon-m-arrows-up-down')
                    ->color('gray'),

                ActionGroup::make([
                    Action::make('limit_10')->label('أفضل 10')->action(fn () => $this->setLimit(10)),
                    Action::make('limit_20')->label('أفضل 20')->action(fn () => $this->setLimit(20)),
                    Action::make('limit_50')->label('أفضل 50')->action(fn () => $this->setLimit(50)),
                ])
                    ->label(fn (): string => 'العدد: '.$this->limit)
                    ->icon('heroicon-m-list-bullet')
                    ->color('gray'),

                ActionGroup::make($this->getCategoryActions())
                    ->label(fn (): string => $this->getSelectedCategoryLabel())
                    ->icon('heroicon-m-tag')
                    ->dropdownMaxHeight('20rem')
                    ->color(fn (): string => $this->categoryUuid !== null ? 'primary' : 'gray'),

                Action::make('toggle_legacy')
                    ->label(fn () => $this->includeLegacy ? 'استبعاد المؤرشفة' : 'شمل المؤرشفة')
                    ->icon('heroicon-m-archive-box')
                    ->color(fn (): string => $this->includeLegacy ? 'warning' : 'gray')
                    ->action(fn () => $this->toggleLegacy()),
            ])
            ->actions([
                Action::make('detail')
                    ->label('التفاصيل')
                    ->icon('heroicon-m-chart-bar')
                    ->slideOver()
                    ->modalHeading(fn (array $record): string => 'تفاصيل الصنف: '.($record['name'] ?? ''))
                    ->modalContent(fn (array $record): View => view('filament.app.reports.product-performance.item-detail', [
                        'detail' => $this->getItemDetail($record['product_ref'] ?? $record['product_uuid'] ?? $record['item_key'] ?? ''),
                    ])),
            ]);
    }

    private function rankByLabel(): string
    {
        return match ($this->rankBy) {
            'qty' => 'الكمية',
            'gross_profit' => 'مجمل الربح',
            'margin_pct' => 'هامش الربح',
            default => 'صافي المبيعات',
        };
    }

    public function getItemDetail(string $key): ?ProductDetailSummary
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = ProductPerformanceReport::for($query);

        return $report->itemDetail($key);
    }

    protected function getReportRecords(): ?Closure
    {
        return function (): array {
            /** @var User|null $user */
            $user = auth()->user();
            if (! $user) {
                return [];
            }

            $query = MetricsQuery::for($user, $this->reportFilters());
            $report = ProductPerformanceReport::for($query);
            $ranking = $report->ranking(
                rankBy: $this->rankBy,
                limit: $this->limit,
                includeLegacy: $this->includeLegacy,
                categoryUuid: $this->categoryUuid,
            );

            $sourceList = match ($this->activeTab) {
                'bottom' => $ranking['bottom'],
                'unsold' => $ranking['unsold'],
                default => $ranking['top'],
            };

            $records = [];
            foreach ($sourceList as $index => $row) {
                $id = $row->itemKey;
                $rankNumber = $index + 1;
                $qtyDecimals = ($row->unit === 'kg' || fmod($row->qtySold, 1.0) > 0.0001) ? 2 : 0;

                $records[$id] = [
                    'id' => $id,
                    'rank' => $rankNumber,
                    'item_key' => $row->itemKey,
                    'product_ref' => $row->productRef,
                    'product_uuid' => $row->productUuid,
                    'name' => $row->name,
                    'category_name' => $row->categoryName,
                    'qty' => $row->isSold ? Format::number($row->qtySold, $qtyDecimals).' '.$row->unit : '—',
                    'sales' => $row->isSold ? Format::money($row->netSales, 2) : '—',
                    'gross_profit' => $row->isSold ? Format::money($row->grossProfit, 2) : '—',
                    'margin_pct' => $row->isSold ? Format::percent($row->marginPct, 1) : '—',
                    'share_of_sales' => $row->isSold ? Format::percent($row->shareOfSales, 1) : '—',
                    'growth' => $row->isSold ? Format::growth($row->salesGrowth) : '—',
                    'group_rank' => $row->groupRank !== null ? "#{$row->groupRank}" : '—',
                ];
            }

            return $records;
        };
    }

    /**
     * @return list<Action>
     */
    protected function getCategoryActions(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $branchIds = $query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        $categories = DB::table('dim_categories')
            ->whereIn('branch_id', $branchIds)
            ->whereNull('deleted_at')
            ->select(['uuid', 'name'])
            ->distinct()
            ->orderBy('name')
            ->get();

        $actions = [
            Action::make('cat_all')
                ->label('كل التصنيفات')
                ->icon($this->categoryUuid === null ? 'heroicon-m-check' : null)
                ->action(fn () => $this->setCategory(null)),
        ];

        foreach ($categories as $cat) {
            $uuid = (string) $cat->uuid;
            $actions[] = Action::make("cat_{$uuid}")
                ->label((string) $cat->name)
                ->icon($this->categoryUuid === $uuid ? 'heroicon-m-check' : null)
                ->action(fn () => $this->setCategory($uuid));
        }

        return $actions;
    }

    protected function getSelectedCategoryLabel(): string
    {
        if ($this->categoryUuid === null) {
            return 'التصنيف: الكل';
        }

        $name = DB::table('dim_categories')
            ->where('uuid', $this->categoryUuid)
            ->value('name');

        return 'التصنيف: '.($name ?: 'محدد');
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('rank')
                ->label('#')
                ->alignCenter(),

            TextColumn::make('name')
                ->label('اسم الصنف')
                ->description(fn (array $record): ?string => $record['product_ref'] ?? null)
                ->searchable(),

            TextColumn::make('category_name')
                ->label('التصنيف')
                ->badge()
                ->color('gray'),

            TextColumn::make('group_rank')
                ->label('الترتيب بالمجموعة')
                ->alignCenter()
                ->badge()
                ->color('info')
                ->visible(fn (): bool => auth()->user()?->can('analytics.view_group') ?? false),

            TextColumn::make('qty')
                ->label('الكمية المباعة')
                ->alignEnd(),

            TextColumn::make('sales')
                ->label('صافي المبيعات')
                ->alignEnd(),

            TextColumn::make('gross_profit')
                ->label('مجمل الربح')
                ->alignEnd(),

            TextColumn::make('margin_pct')
                ->label('هامش الربح')
                ->alignEnd(),

            TextColumn::make('share_of_sales')
                ->label('الحصة')
                ->alignEnd(),

            TextColumn::make('growth')
                ->label('النمو')
                ->alignEnd(),
        ];
    }
}
