<div class="space-y-4">
    @if ($uncategorizedCount > 0)
        <div class="flex items-center justify-between gap-4 p-4 rounded-xl border border-warning-200 bg-warning-50 dark:border-warning-800/40 dark:bg-warning-950/20 text-warning-900 dark:text-warning-200">
            <div class="flex items-center gap-3">
                <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-warning-600 dark:text-warning-400 shrink-0" />
                <div>
                    <h4 class="text-sm font-semibold">
                        يوجد {{ $uncategorizedCount }} أنواع مصروفات غير مصنفة (محددة كـ "أخرى")
                    </h4>
                    <p class="text-xs text-warning-700 dark:text-warning-300 mt-0.5">
                        يُنصح بتحديد التصنيف الدقيق لكل بند (عمالة، إيجار، مرافق، تسويق، إلخ) لضمان دقة مؤشرات تكلفة التشغيل وهوامش الربح.
                    </p>
                </div>
            </div>
            @if ($mappingUrl)
                <a
                    href="{{ $mappingUrl }}"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-warning-900 dark:text-warning-100 bg-warning-100 hover:bg-warning-200 dark:bg-warning-900/40 dark:hover:bg-warning-900/60 rounded-lg transition"
                >
                    <span>تعيين تصنيفات المصروفات</span>
                    <x-heroicon-m-arrow-left class="w-4 h-4 rtl:rotate-0 ltr:rotate-180" />
                </a>
            @endif
        </div>
    @endif

    <div class="flex items-start gap-3 p-3.5 rounded-xl border border-info-200 bg-info-50/50 dark:border-info-800/30 dark:bg-info-950/20 text-info-900 dark:text-info-200 text-xs leading-relaxed">
        <x-heroicon-o-information-circle class="w-5 h-5 text-info-600 dark:text-info-400 shrink-0 mt-0.5" />
        <div>
            <span class="font-semibold">تنبيه محاسبي:</span>
            المشتريات لا تُضاف إلى المصروفات التشغيلية ولا تُخصم مباشرة من الأرباح؛ بل تسجل في تكلفة البضاعة المباعة (COGS) عند استهلاكها وفقاً للمعايير المحاسبية (المشتريات تمثل تدفقاً نقدياً ومخزوناً).
        </div>
    </div>
</div>
