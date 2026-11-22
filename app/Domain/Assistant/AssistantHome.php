<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Filament\App\Pages\Management\AiSettingsPage;
use App\Models\User;
use Illuminate\Support\Str;

final class AssistantHome
{
    /**
     * @return array{
     *     greeting: string,
     *     starterPrompts: list<string>,
     *     readiness: array{ready: bool, message: ?string, settingsUrl: ?string}|null,
     *     can: array{manageCombos: bool},
     *     toolLabels: array<string, string>
     * }
     */
    public static function forUser(User $user): array
    {
        return [
            'greeting' => self::greeting($user),
            'starterPrompts' => self::starterPrompts($user),
            'readiness' => self::readiness($user),
            'can' => [
                'manageCombos' => (bool) $user->can('ai.manage'),
            ],
            'toolLabels' => self::toolLabels(),
        ];
    }

    /**
     * Arabic labels for tool running states, displayed in the chat and cards.
     *
     * @return array<string, string>
     */
    public static function toolLabels(): array
    {
        return [
            'get_kpis' => '⏳ بحسب المؤشرات…',
            'get_kpi_series' => '⏳ بحلل الاتجاهات…',
            'query_metrics' => '⏳ بستعلم عن البيانات…',
            'overview_report' => '⏳ بجهز التقرير الشامل…',
            'sales_comparison' => '⏳ بقارن المبيعات…',
            'profitability' => '⏳ بحلل الربحية…',
            'food_cost_waste' => '⏳ بحسب تكلفة الخامات والهالك…',
            'product_performance' => '⏳ بحلل أداء الأصناف…',
            'shifts_weekdays' => '⏳ بحلل الورديات والأيام…',
            'branch_ranking' => '⏳ برتب الفروع…',
            'targets_status' => '⏳ بفحص المستهدفات…',
            'expenses_purchases' => '⏳ براجع المصروفات والمشتريات…',
            'data_health' => '⏳ بفحص صحة البيانات…',
            'analyze_growth' => '⏳ بحلل النمو…',
            'detect_anomalies' => '⏳ بفحص التغيرات غير المعتادة…',
            'analyze_profit_contribution' => '⏳ بحلل مساهمة الأرباح…',
            'analyze_cost_ratios' => '⏳ بحلل نسب التكاليف…',
            'forecast_sales' => '⏳ بحسب التوقعات…',
            'forecast_material_needs' => '⏳ بتوقع احتياجات المواد…',
            'menu_engineering' => '⏳ بهندس قائمة الطعام…',
            'get_workspace_context' => '⏳ بجهز سياق العمل…',
            'resolve_period' => '⏳ بحدد الفترة الزمنية…',
            'find_branches' => '⏳ ببحث عن الفروع…',
            'search_products' => '⏳ ببحث عن الأصناف…',
            'rank_items' => '⏳ برتب الأصناف اللي تستاهل تركيز…',
            'get_combo_candidates' => '⏳ بجيب العروض الجاهزة بهامش ربح آمن…',
            'get_item_card' => '⏳ بجهز كارت الصنف…',
            'get_affinities' => '⏳ بشوف الأصناف اللي بتتطلب مع بعض…',
            'default' => '⏳ بجهز البيانات…',
        ];
    }

    public static function greeting(User $user): string
    {
        $name = Str::of((string) $user->name)->trim()->before(' ')->toString();
        $greeting = now()->hour < 12 ? 'صباح الخير' : 'مساء الخير';

        return $name !== '' ? "{$greeting}، {$name}" : $greeting;
    }

    /**
     * @return list<string>
     */
    public static function starterPrompts(?User $user = null): array
    {
        if ($user !== null && $user->hasRole('branch_manager')) {
            return [
                'ليه مبيعات فرعي نزلت الأسبوع ده؟',
                'إيه أكتر 5 أصناف مبيعاً في الفرع؟',
                'هل هنحقق مستهدف الفرع الشهر ده؟',
                'توقع مبيعات الفرع لآخر الشهر',
            ];
        }

        return [
            'ليه مبيعات فرع المعادي نزلت الأسبوع ده؟',
            'إيه أكتر 5 أصناف بتخسر؟',
            'هنحقق المستهدف الشهر ده؟',
            'توقع المبيعات لآخر الشهر',
        ];
    }

    public static function readinessResult(User $user): ?AssistantReadinessResult
    {
        $organization = $user->organization;

        if ($organization === null || ! class_exists(AssistantReadiness::class)) {
            return null;
        }

        return app(AssistantReadiness::class)->check($organization);
    }

    /**
     * @return array{ready: bool, message: ?string, settingsUrl: ?string}|null
     */
    public static function readiness(User $user): ?array
    {
        $result = self::readinessResult($user);

        if ($result === null) {
            return null;
        }

        return [
            'ready' => $result->ready,
            'message' => $result->ready ? null : match ($result->reason) {
                'missing_key' => 'مزود الذكاء الاصطناعي المختار محتاج مفتاح اتصال.',
                'data_sharing_not_acknowledged' => 'لازم توافق على مشاركة الأرقام المجمعة مع مزود الذكاء الاصطناعي قبل أول سؤال.',
                default => 'لسه ما اتحددش مزود الذكاء الاصطناعي اللي هيجاوب على أسئلتك.',
            },
            'settingsUrl' => self::settingsUrl($user),
        ];
    }

    public static function settingsUrl(User $user): ?string
    {
        if (! class_exists(AiSettingsPage::class) || ! $user->can('ai.manage')) {
            return null;
        }

        try {
            return AiSettingsPage::getUrl(panel: 'app');
        } catch (\Throwable) {
            return null;
        }
    }
}
