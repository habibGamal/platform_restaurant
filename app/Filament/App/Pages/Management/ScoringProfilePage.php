<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Management;

use App\Domain\Metrics\Reports\BranchRankingReport;
use App\Models\ScoringProfile;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

class ScoringProfilePage extends Page
{
    protected static string $routePath = 'management/scoring-profile';

    protected static ?string $title = 'معايير التقييم';

    protected static ?string $navigationLabel = 'معايير التقييم';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user !== null && $user->can('users.manage');
    }

    public function mount(): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || ! $user->can('users.manage')) {
            abort(403);
        }

        $weights = BranchRankingReport::DEFAULT_WEIGHTS;

        if ($user->organization_id) {
            /** @var ScoringProfile|null $profile */
            $profile = ScoringProfile::query()->where('organization_id', $user->organization_id)->first();
            if ($profile) {
                /** @var array<string, mixed>|string|null $raw */
                $raw = $profile->getAttribute('weights');
                if (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                    $raw = is_array($decoded) ? $decoded : null;
                }
                if (is_array($raw)) {
                    foreach (BranchRankingReport::DEFAULT_WEIGHTS as $k => $def) {
                        if (isset($raw[$k]) && is_numeric($raw[$k])) {
                            $weights[$k] = (float) $raw[$k];
                        }
                    }
                }
            }
        }

        $this->data = $weights;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Form::make([
                    Section::make('أوزان معايير التقييم')
                        ->description('حدد الوزن النسبي لكل مؤشر أداء (يجب أن يكون مجموع الأوزان 100% بالضبط)')
                        ->components([
                            TextInput::make('net_margin')
                                ->label('هامش صافي الربح (K15)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),

                            TextInput::make('sales_growth')
                                ->label('نمو المبيعات (K24)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),

                            TextInput::make('target_achievement')
                                ->label('تحقيق المستهدف (K23)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),

                            TextInput::make('food_cost_pct')
                                ->label('نسبة تكلفة الخامات (K16)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),

                            TextInput::make('waste_pct')
                                ->label('نسبة الهالك (K17)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),

                            TextInput::make('avg_ticket_growth')
                                ->label('نمو متوسط الطلب')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),

                            TextInput::make('cancel_rate')
                                ->label('نسبة الإلغاء (K21)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),
                        ]),
                ])
                    ->livewireSubmitHandler('save'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('حفظ التعديلات')
                ->action('save')
                ->color('primary'),
        ];
    }

    public function save(): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null || ! $user->can('users.manage')) {
            abort(403);
        }

        $this->validate([
            'data.net_margin' => ['required', 'numeric', 'min:0', 'max:100'],
            'data.sales_growth' => ['required', 'numeric', 'min:0', 'max:100'],
            'data.target_achievement' => ['required', 'numeric', 'min:0', 'max:100'],
            'data.food_cost_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'data.waste_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'data.avg_ticket_growth' => ['required', 'numeric', 'min:0', 'max:100'],
            'data.cancel_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ], [
            'data.*.required' => 'هذا الحقل مطلوب.',
            'data.*.numeric' => 'يجب أن يكون الوزن رقماً.',
        ]);

        $weights = [
            'net_margin' => (float) ($this->data['net_margin'] ?? 0),
            'sales_growth' => (float) ($this->data['sales_growth'] ?? 0),
            'target_achievement' => (float) ($this->data['target_achievement'] ?? 0),
            'food_cost_pct' => (float) ($this->data['food_cost_pct'] ?? 0),
            'waste_pct' => (float) ($this->data['waste_pct'] ?? 0),
            'avg_ticket_growth' => (float) ($this->data['avg_ticket_growth'] ?? 0),
            'cancel_rate' => (float) ($this->data['cancel_rate'] ?? 0),
        ];

        $sum = array_sum($weights);
        if (round($sum, 2) !== 100.0) {
            $msg = "يجب أن يكون مجموع أوزان معايير التقييم 100% بالضبط (المجموع الحالي: {$sum}%).";
            throw ValidationException::withMessages([
                'data.weights' => $msg,
                'weights' => $msg,
            ]);
        }

        if ($user->organization_id) {
            ScoringProfile::updateOrCreate(
                ['organization_id' => $user->organization_id],
                ['weights' => $weights]
            );
        }

        Notification::make()
            ->title('تم حفظ معايير التقييم بنجاح')
            ->success()
            ->send();
    }
}
