<?php

namespace App\Filament\App\Resources\TargetResource\Pages;

use App\Filament\App\Resources\TargetResource;
use App\Models\Target;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ListTargets extends ListRecords
{
    protected static string $resource = TargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('copyPreviousMonth')
                ->label('نسخ الشهر السابق')
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => auth()->user()?->can('targets.manage') ?? false)
                ->schema([
                    Forms\Components\DatePicker::make('target_month')
                        ->label('الشهر المستهدف المراد النسخ إليه')
                        ->displayFormat('Y-m')
                        ->required()
                        ->default(now()->addMonth()->startOfMonth()),
                ])
                ->action(function (array $data): void {
                    $targetMonth = Carbon::parse($data['target_month'])->startOfMonth();
                    $previousMonth = $targetMonth->copy()->subMonth()->startOfMonth();
                    $orgId = auth()->user()?->organization_id;

                    $sourceTargets = Target::query()
                        ->where('organization_id', $orgId)
                        ->where('period_month', $previousMonth->format('Y-m-d'))
                        ->get();

                    $copiedCount = 0;
                    foreach ($sourceTargets as $source) {
                        $alreadyExists = Target::query()
                            ->where('organization_id', $orgId)
                            ->where('branch_id', $source->branch_id)
                            ->where('metric', $source->metric)
                            ->where('period_month', $targetMonth->format('Y-m-d'))
                            ->exists();

                        if (! $alreadyExists) {
                            Target::create([
                                'organization_id' => $orgId,
                                'branch_id' => $source->branch_id,
                                'metric' => $source->metric,
                                'period_month' => $targetMonth->format('Y-m-d'),
                                'value' => $source->value,
                                'distribution' => $source->distribution,
                                'notes' => $source->notes,
                                'created_by' => auth()->id(),
                                'updated_by' => auth()->id(),
                            ]);
                            $copiedCount++;
                        }
                    }

                    Notification::make()
                        ->title('تم نسخ الأهداف بنجاح')
                        ->body("تم نسخ {$copiedCount} هدف من شهر {$previousMonth->format('Y-m')} إلى شهر {$targetMonth->format('Y-m')}.")
                        ->success()
                        ->send();
                }),
            Action::make('copyLastYearWithPercent')
                ->label('الشهر المماثل من العام الماضي + نسبة')
                ->icon('heroicon-o-chart-bar')
                ->visible(fn (): bool => auth()->user()?->can('targets.manage') ?? false)
                ->schema([
                    Forms\Components\DatePicker::make('target_month')
                        ->label('الشهر المستهدف')
                        ->displayFormat('Y-m')
                        ->required()
                        ->default(now()->startOfMonth()),
                    Forms\Components\Select::make('metric')
                        ->label('المؤشر')
                        ->options([
                            'all' => 'جميع المؤشرات (المبيعات، صافي الربح، عدد الطلبات)',
                            'net_sales' => 'المبيعات',
                            'net_profit' => 'صافي الربح',
                            'orders' => 'عدد الطلبات',
                        ])
                        ->default('all')
                        ->required(),
                    Forms\Components\TextInput::make('percent')
                        ->label('نسبة التغيير (%)')
                        ->helperText('أدخل النسبة المئوية للزيادة أو النقصان (مثلاً: 10 تعني +10%)')
                        ->numeric()
                        ->default(10)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $targetMonth = Carbon::parse($data['target_month'])->startOfMonth();
                    $lastYearStart = $targetMonth->copy()->subYear()->startOfMonth();
                    $lastYearEnd = $targetMonth->copy()->subYear()->endOfMonth();
                    $percent = (float) $data['percent'];
                    $multiplier = 1 + ($percent / 100);
                    $orgId = auth()->user()?->organization_id;

                    $metrics = match ($data['metric']) {
                        'all' => ['net_sales', 'net_profit', 'orders'],
                        default => [$data['metric']],
                    };

                    $historyRows = DB::table('agg_branch_daily')
                        ->where('organization_id', $orgId)
                        ->whereBetween('business_date', [$lastYearStart->format('Y-m-d'), $lastYearEnd->format('Y-m-d')])
                        ->groupBy('branch_id')
                        ->selectRaw('
                            branch_id,
                            SUM(net_sales_after_returns) as total_net_sales,
                            SUM(net_profit) as total_net_profit,
                            SUM(orders_count) as total_orders
                        ')
                        ->get();

                    $processed = 0;
                    foreach ($historyRows as $row) {
                        foreach ($metrics as $metric) {
                            $actual = match ($metric) {
                                'net_sales' => (float) $row->total_net_sales,
                                'net_profit' => (float) $row->total_net_profit,
                                'orders' => (float) $row->total_orders,
                                default => 0.0,
                            };

                            // Skip branches with no history for this metric
                            if ($actual == 0 && (int) $row->total_orders === 0 && (float) $row->total_net_sales == 0) {
                                continue;
                            }

                            $targetValue = $actual * $multiplier;
                            if ($metric === 'orders') {
                                $targetValue = round($targetValue);
                            }

                            Target::updateOrCreate(
                                [
                                    'organization_id' => $orgId,
                                    'branch_id' => $row->branch_id,
                                    'metric' => $metric,
                                    'period_month' => $targetMonth->format('Y-m-d'),
                                ],
                                [
                                    'value' => $targetValue,
                                    'distribution' => 'weekday_weights',
                                    'created_by' => auth()->id(),
                                    'updated_by' => auth()->id(),
                                ]
                            );
                            $processed++;
                        }
                    }

                    Notification::make()
                        ->title('تم إنشاء الأهداف بنجاح')
                        ->body("تم احتساب وتعيين {$processed} هدف بناءً على أداء نفس الشهر من العام الماضي مع نسبة {$percent}%.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
