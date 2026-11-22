<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Management;

use App\Domain\Menu\Intelligence\ComboDecisions;
use App\Domain\Menu\Intelligence\ComboTemplate;
use App\Domain\Menu\Intelligence\PeriodKeys;
use App\Domain\Metrics\Reports\OrderTypeSales;
use App\Models\Menu\ComboCandidate;
use App\Models\Menu\ItemProfile;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/** Lets the owner approve or reject the combos the nightly build proposes. */
class ComboCandidatesPage extends Page implements HasTable
{
    use InteractsWithTable;

    private const STATUS_LABELS = [
        'candidate' => 'مقترح',
        'approved' => 'معتمد',
        'rejected' => 'مرفوض',
    ];

    protected static string $routePath = 'management/combo-candidates';

    protected static ?string $title = 'الكومبوهات المقترحة';

    protected static ?string $navigationLabel = 'الكومبوهات المقترحة';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 24;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

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
        $periods = $this->distinctValues('period_key');
        rsort($periods, SORT_STRING);

        return $table
            ->heading('الكومبوهات المقترحة')
            ->description('اعتماد أو رفض كومبو بيتطبق على نفس التشكيلة في كل الفترات، والبناء الليلي بيحافظ على قرارك.')
            ->records(fn (?string $search, ?array $filters, int|string $page, int|string|null $recordsPerPage): LengthAwarePaginator => $this->paginatedCandidates($search, $filters ?? [], (int) $page, (int) ($recordsPerPage ?: 25)))
            ->columns([
                TextColumn::make('hero_name')
                    ->label('الصنف الأساسي')
                    ->wrap(),
                TextColumn::make('items_label')
                    ->label('التشكيلة')
                    ->wrap(),
                TextColumn::make('template_label')
                    ->label('القالب'),
                TextColumn::make('list_total')
                    ->label('السعر الأصلي')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('suggested_price')
                    ->label('السعر المقترح')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('margin_label')
                    ->label('هامش الربح'),
                TextColumn::make('discount_label')
                    ->label('الخصم'),
                TextColumn::make('evidence_label')
                    ->label('الدليل')
                    ->tooltip(fn (array $record): ?string => $record['evidence_why'])
                    ->wrap(),
                TextColumn::make('overlap_label')
                    ->label('تنبيه')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('score')
                    ->label('التقييم')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('status_label')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn (array $record): string => match ($record['status']) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('period_key')
                    ->label('الفترة')
                    ->options(array_combine($periods, array_map(self::periodLabel(...), $periods)))
                    ->default($this->defaultPeriod($periods)),
                SelectFilter::make('template')
                    ->label('القالب')
                    ->options(collect(ComboTemplate::cases())->mapWithKeys(fn (ComboTemplate $template): array => [$template->value => $template->label()])->all()),
                SelectFilter::make('channel')
                    ->label('القناة')
                    ->options(collect($this->distinctValues('channel'))->mapWithKeys(fn (string $channel): array => [$channel => self::channelLabel($channel)])->all()),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(self::STATUS_LABELS),
            ])
            ->recordActions([
                $this->decisionAction('approve', 'approved', 'اعتماد', Heroicon::OutlinedCheck, 'success'),
                $this->decisionAction('reject', 'rejected', 'رفض', Heroicon::OutlinedXMark, 'danger'),
                Action::make('reset')
                    ->label('رجوع لمقترح')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (array $record): bool => $record['status'] !== 'candidate')
                    ->requiresConfirmation()
                    ->action(function (array $record): void {
                        $this->decide((string) $record['signature'], 'candidate', null);
                    }),
            ])
            ->searchable()
            ->paginated([25, 50, 100])
            ->emptyStateHeading('لا توجد كومبوهات مقترحة')
            ->emptyStateDescription('بتتجهز الكومبوهات في البناء الليلي من بطاقات الأصناف وبيانات السلة.');
    }

    private function decisionAction(string $name, string $status, string $label, Heroicon $icon, string $color): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->visible(fn (array $record): bool => $record['status'] !== $status)
            ->modalHeading($label.' الكومبو')
            ->schema([
                Textarea::make('owner_note')
                    ->label('ملاحظة (اختياري)')
                    ->maxLength(1000),
            ])
            ->fillForm(fn (array $record): array => ['owner_note' => $record['owner_note']])
            ->action(function (array $record, array $data) use ($status): void {
                $this->decide((string) $record['signature'], $status, $data['owner_note'] ?? null);
            });
    }

    /** Every period's row of the combo gets the decision, so the next build keeps it whatever period it reads. */
    private function decide(string $signature, string $status, mixed $note): void
    {
        ComboDecisions::apply(
            $this->organizationId(),
            $signature,
            $status,
            is_string($note) ? $note : null,
        );

        Notification::make()->title('تم حفظ القرار')->success()->send();
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

    /** @return list<string> */
    private function distinctValues(string $column): array
    {
        return array_values(ComboCandidate::query()
            ->where('organization_id', $this->organizationId())
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(fn ($value): string => (string) $value)
            ->all());
    }

    /**
     * The latest complete month present, else the latest period present.
     *
     * @param  list<string>  $periods  newest first
     */
    private function defaultPeriod(array $periods): ?string
    {
        $currentMonth = CarbonImmutable::today()->format('Y-m');
        foreach ($periods as $period) {
            if (preg_match('/^\d{4}-\d{2}$/', $period) === 1 && $period < $currentMonth) {
                return $period;
            }
        }

        return $periods[0] ?? null;
    }

    private static function periodLabel(string $period): string
    {
        if ($period === PeriodKeys::ROLLING_90D) {
            return 'آخر 90 يوم';
        }

        return $period === CarbonImmutable::today()->format('Y-m') ? $period.' (لحد امبارح)' : $period;
    }

    private static function channelLabel(string $channel): string
    {
        return match ($channel) {
            'all' => 'كل القنوات',
            'talabat' => 'طلبات',
            'companies' => 'شركات',
            default => OrderTypeSales::LABELS[$channel] ?? $channel,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginatedCandidates(?string $search, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $rows = $this->candidates($filters);

        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $rows = array_filter($rows, fn (array $row): bool => str_contains(mb_strtolower($row['items_label']), $needle));
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage, true), count($rows), $perPage, $page);
    }

    /**
     * The organization's candidates matching the filters, best score first.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>> keyed by candidate id
     */
    private function candidates(array $filters): array
    {
        $orgId = $this->organizationId();
        $query = ComboCandidate::query()->where('organization_id', $orgId);

        foreach (['period_key', 'template', 'channel', 'status'] as $column) {
            $value = $filters[$column]['value'] ?? null;
            if (is_string($value) && $value !== '') {
                $query->where($column, $value);
            }
        }

        $candidates = $query->orderByDesc('score')->orderBy('id')->get();

        $overlapKeys = $candidates->pluck('overlaps_combo_key')->filter()->unique()->values()->all();
        $overlapNames = $overlapKeys === [] ? collect() : ItemProfile::query()
            ->where('organization_id', $orgId)
            ->whereIn('item_key', $overlapKeys)
            ->pluck('name', 'item_key');

        $rows = [];
        foreach ($candidates as $candidate) {
            $heroName = $candidate->hero_key;
            $parts = [];
            foreach ($candidate->items as $item) {
                if ($item['key'] === $candidate->hero_key) {
                    $heroName = $item['name'];
                }
                $parts[] = ((int) $item['qty'] > 1 ? (int) $item['qty'].'× ' : '').$item['name'];
            }

            $evidence = $candidate->evidence;
            $observed = ($evidence['type'] ?? null) === 'observed';
            $overlap = $candidate->overlaps_combo_key;

            $rows[$candidate->id] = [
                'id' => $candidate->id,
                'signature' => $candidate->signature,
                'hero_name' => $heroName,
                'items_label' => implode(' + ', $parts),
                'template_label' => ComboTemplate::tryFrom($candidate->template)?->label() ?? $candidate->template,
                'list_total' => $candidate->list_total,
                'suggested_price' => $candidate->suggested_price,
                'margin_label' => number_format($candidate->margin_pct * 100, 1).'%',
                'discount_label' => number_format($candidate->discount_pct * 100, 1).'%',
                'evidence_label' => $observed
                    ? 'اتطلبت مع بعض في '.(int) ($evidence['together_orders'] ?? 0).' طلب'
                    : 'مبنية على قواعد القالب',
                'evidence_why' => is_string($evidence['why'] ?? null) ? $evidence['why'] : null,
                'overlap_label' => $overlap !== null ? 'شبه كومبو موجود: '.($overlapNames[$overlap] ?? $overlap) : null,
                'score' => $candidate->score,
                'status' => $candidate->status,
                'status_label' => self::STATUS_LABELS[$candidate->status] ?? $candidate->status,
                'owner_note' => $candidate->owner_note,
            ];
        }

        return $rows;
    }
}
