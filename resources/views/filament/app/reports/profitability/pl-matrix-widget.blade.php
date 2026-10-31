<x-filament-widgets::widget class="fi-wi-table">
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <span class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $heading }}
                </span>

                {{-- Widget Controls Toolbar --}}
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    {{-- Labor Basis Toggle --}}
                    <div class="inline-flex rounded-lg border border-gray-200 dark:border-white/10 p-0.5 bg-gray-50 dark:bg-white/5">
                        <x-filament::button
                            size="xs"
                            :color="$laborBasis === 'paid' ? 'primary' : 'gray'"
                            wire:click="setLaborBasis('paid')"
                            title="حساب تكلفة العمالة والإيجار بناءً على المدفوع فعلياً المسجل"
                        >
                            مدفوع فعلياً
                        </x-filament::button>
                        <x-filament::button
                            size="xs"
                            :color="$laborBasis === 'allocated' ? 'primary' : 'gray'"
                            wire:click="setLaborBasis('allocated')"
                            title="تحميل تكلفة العمالة والإيجار الشهرية نسبياً على أيام الفترة"
                        >
                            محمّل (Allocated)
                        </x-filament::button>
                    </div>

                    {{-- Exclude Inventory Variance Toggle --}}
                    <x-filament::button
                        size="xs"
                        :color="$excludeInventoryVariance ? 'primary' : 'gray'"
                        wire:click="toggleExcludeInventoryVariance"
                        title="استبعاد عجز الجرد من صافي الربح لتجنب تأثير جرد اليوم الواحد"
                    >
                        {{ $excludeInventoryVariance ? '✓ تم استبعاد عجز الجرد' : 'استبعاد عجز الجرد' }}
                    </x-filament::button>

                    {{-- Comparison Delta Toggle --}}
                    @if ($hasComparison)
                        <x-filament::button
                            size="xs"
                            :color="$showComparisonDelta ? 'primary' : 'gray'"
                            wire:click="toggleComparisonDelta"
                        >
                            {{ $showComparisonDelta ? 'إخفاء الفروق (Δ)' : 'عرض الفروق (Δ)' }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        </x-slot>

        {{-- Alert Notices --}}
        <div class="space-y-2 mb-4">
            @if (! empty($reportData->stocktakingNotes))
                <details class="group rounded-xl border border-amber-200 bg-amber-50/60 dark:border-amber-800/40 dark:bg-amber-950/20 p-3 text-xs text-amber-900 dark:text-amber-200">
                    <summary class="flex items-center justify-between cursor-pointer select-none font-semibold">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-m-information-circle" class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                            <span>ملاحظات الجرد الدوري وعجز المخزون ({{ count($reportData->stocktakingNotes) }})</span>
                            @if (! $excludeInventoryVariance)
                                <x-filament::button size="xs" color="warning" wire:click.stop="toggleExcludeInventoryVariance">
                                    (انقر هنا لاستبعاد عجز الجرد)
                                </x-filament::button>
                            @endif
                        </div>
                        <x-filament::icon icon="heroicon-m-chevron-down" class="w-4 h-4 transition-transform group-open:rotate-180" />
                    </summary>
                    <div class="mt-2.5 pt-2 border-t border-amber-200/60 dark:border-amber-800/40 space-y-1.5 font-normal leading-relaxed whitespace-pre-wrap">
                        @foreach ($reportData->stocktakingNotes as $note)
                            <div class="p-1.5 rounded bg-amber-100/50 dark:bg-amber-900/20">
                                {{ $note }}
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif

            @if (! empty($reportData->missingExpenseBranches))
                <div class="flex items-center gap-2 p-3 text-xs rounded-xl bg-danger-50 dark:bg-danger-950/30 text-danger-800 dark:text-danger-200 border border-danger-200 dark:border-danger-800/40">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="w-4 h-4 text-danger-600 dark:text-danger-400 shrink-0" />
                    <div class="flex-1">
                        <span class="font-bold">⚠️ تنبيه نقص بيانات:</span>
                        <span>الفروع التالية لديها مبيعات ولكن لا توجد مصروفات مسجلة خلال الفترة: {{ implode('، ', $reportData->missingExpenseBranches) }}</span>
                    </div>
                </div>
            @endif
        </div>

        {{-- Native Table from BaseReportViewWidget --}}
        {{ $this->table }}
    </x-filament::section>
</x-filament-widgets::widget>
