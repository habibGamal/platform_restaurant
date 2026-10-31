<x-filament-widgets::widget>
    <div class="space-y-4">
        @if ($uncategorizedCount > 0)
            <x-filament::callout color="warning" icon="heroicon-o-exclamation-triangle"
                :heading="'يوجد '.$uncategorizedCount.' أنواع مصروفات غير مصنفة (محددة كـ أخرى)'"
                description="يُنصح بتحديد التصنيف الدقيق لكل بند (عمالة، إيجار، مرافق، تسويق، إلخ) لضمان دقة مؤشرات تكلفة التشغيل وهوامش الربح.">
                @if ($mappingUrl)
                    <x-slot name="footer">
                        <x-filament::button tag="a" :href="$mappingUrl" size="xs" color="warning" icon="heroicon-m-arrow-left" icon-position="after">
                            تعيين تصنيفات المصروفات
                        </x-filament::button>
                    </x-slot>
                @endif
            </x-filament::callout>
        @endif
        <x-filament::callout color="info" icon="heroicon-o-information-circle" heading="تنبيه محاسبي:"
            description="المشتريات لا تُضاف إلى المصروفات التشغيلية ولا تُخصم مباشرة من الأرباح؛ بل تسجل في تكلفة البضاعة المباعة (COGS) عند استهلاكها وفقاً للمعايير المحاسبية (المشتريات تمثل تدفقاً نقدياً ومخزوناً)." />
    </div>
</x-filament-widgets::widget>
