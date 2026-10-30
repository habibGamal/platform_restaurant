<x-filament::section>
    <x-slot name="heading">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <span class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $heading }}
                </span>
            </div>

            {{-- Widget Controls Toolbar --}}
            <div class="flex flex-wrap items-center gap-2 text-xs">
                {{-- Labor Basis Toggle --}}
                <div class="inline-flex rounded-lg border border-gray-200 dark:border-white/10 p-0.5 bg-gray-50 dark:bg-white/5">
                    <button
                        type="button"
                        wire:click="setLaborBasis('paid')"
                        @class([
                            'px-2.5 py-1 rounded-md font-medium transition',
                            'bg-white dark:bg-white/10 text-primary-600 dark:text-primary-400 shadow-sm' => $laborBasis === 'paid',
                            'text-gray-600 dark:text-gray-300 hover:text-gray-900' => $laborBasis !== 'paid',
                        ])
                        title="حساب تكلفة العمالة والإيجار بناءً على المدفوع فعلياً المسجل">
                        مدفوع فعلياً
                    </button>
                    <button
                        type="button"
                        wire:click="setLaborBasis('allocated')"
                        @class([
                            'px-2.5 py-1 rounded-md font-medium transition',
                            'bg-white dark:bg-white/10 text-primary-600 dark:text-primary-400 shadow-sm' => $laborBasis === 'allocated',
                            'text-gray-600 dark:text-gray-300 hover:text-gray-900' => $laborBasis !== 'allocated',
                        ])
                        title="تحميل تكلفة العمالة والإيجار الشهرية نسبياً على أيام الفترة">
                        محمّل (Allocated)
                    </button>
                </div>

                {{-- Exclude Inventory Variance Toggle --}}
                <button
                    type="button"
                    wire:click="toggleExcludeInventoryVariance"
                    @class([
                        'px-2.5 py-1.5 rounded-lg border transition font-medium',
                        'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300' => $excludeInventoryVariance,
                        'border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 text-gray-600 dark:text-gray-300 hover:bg-gray-50' => ! $excludeInventoryVariance,
                    ])
                    title="استبعاد عجز الجرد من صافي الربح لتجنب تأثير جرد اليوم الواحد">
                    @if ($excludeInventoryVariance)
                        ✓ تم استبعاد عجز الجرد
                    @else
                        استبعاد عجز الجرد
                    @endif
                </button>

                {{-- Comparison Delta Toggle --}}
                @if ($hasComparison)
                    <button
                        type="button"
                        wire:click="toggleComparisonDelta"
                        @class([
                            'px-2.5 py-1.5 rounded-lg border transition font-medium',
                            'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300' => $showComparisonDelta,
                            'border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 text-gray-600 dark:text-gray-300 hover:bg-gray-50' => ! $showComparisonDelta,
                        ])>
                        {{ $showComparisonDelta ? 'إخفاء الفروق (Δ)' : 'عرض الفروق (Δ)' }}
                    </button>
                @endif
            </div>
        </div>
    </x-slot>

    {{-- Alert Notices --}}
    <div class="space-y-2 mb-4">
        @if (! empty($reportData->stocktakingNotes))
            @foreach ($reportData->stocktakingNotes as $note)
                <div class="flex items-center gap-2 px-3 py-2 text-xs rounded-lg bg-amber-50 dark:bg-amber-950/30 text-amber-800 dark:text-amber-200 border border-amber-200/60 dark:border-amber-800/40">
                    <span class="font-bold">📌 ملاحظة:</span>
                    <span>{{ $note }}</span>
                    @if (! $excludeInventoryVariance)
                        <span class="text-amber-600 dark:text-amber-400 underline cursor-pointer" wire:click="toggleExcludeInventoryVariance">
                            (انقر هنا لاستبعاده)
                        </span>
                    @endif
                </div>
            @endforeach
        @endif

        @if (! empty($reportData->missingExpenseBranches))
            <div class="flex items-center gap-2 px-3 py-2 text-xs rounded-lg bg-red-50 dark:bg-red-950/30 text-red-800 dark:text-red-200 border border-red-200/60 dark:border-red-800/40">
                <span class="font-bold">⚠️ تنبيه نقص بيانات:</span>
                <span>الفروع التالية لديها مبيعات ولكن لا توجد مصروفات مسجلة خلال الفترة: {{ implode('، ', $reportData->missingExpenseBranches) }}</span>
            </div>
        @endif
    </div>

    {{-- P&L Matrix Table --}}
    <div class="overflow-x-auto">
        <table class="w-full text-start text-sm divide-y divide-gray-200 dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-900 dark:text-white min-w-[200px]">
                        البند
                    </th>
                    @foreach ($reportData->branches as $branchId => $branch)
                        <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-900 dark:text-white min-w-[150px]">
                            <div class="flex items-center gap-2">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ \App\Support\BranchColor::for($branchId) }}"></span>
                                <span>{{ $branch->branchName }}</span>
                                @if ($branch->hasNoExpenses)
                                    <span class="text-[10px] text-danger-600 bg-danger-50 dark:bg-danger-950/50 px-1 rounded" title="لا توجد مصروفات مسجلة">⚠️</span>
                                @endif
                            </div>
                        </th>
                    @endforeach

                    @if ($reportData->group !== null)
                        <th scope="col" class="px-4 py-3 text-start font-bold text-gray-900 dark:text-white bg-gray-100/70 dark:bg-white/10 min-w-[160px]">
                            الإجمالي (المجموعة)
                        </th>
                    @endif
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($rowDefinitions as $key => $def)
                    @php
                        $isHighlight = $def['is_highlight'] ?? false;
                        $isNetProfit = $def['is_net_profit'] ?? false;
                    @endphp
                    <tr @class([
                        'transition hover:bg-gray-50/50 dark:hover:bg-white/5',
                        'bg-emerald-50/40 dark:bg-emerald-950/20 font-semibold' => $isHighlight && ! $isNetProfit,
                        'bg-primary-50/50 dark:bg-primary-950/30 font-bold border-t-2 border-primary-500/40' => $isNetProfit,
                    ])>
                        {{-- Metric label --}}
                        <td class="px-4 py-3 text-gray-900 dark:text-gray-100">
                            {{ $def['label'] }}
                        </td>

                        {{-- Branch cells --}}
                        @foreach ($reportData->branches as $branchId => $branch)
                            @php
                                $cell = $this->formatCell($branch, $key, $def);
                            @endphp
                            <td class="px-4 py-3 text-gray-800 dark:text-gray-200">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium">{{ $cell['primary'] }}</span>
                                    <div class="flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                                        @if ($cell['pctOfSales'] !== null)
                                            <span>{{ $cell['pctOfSales'] }}</span>
                                        @endif
                                        @if ($cell['delta'] !== null)
                                            <span class="{{ $cell['deltaColor'] }} font-semibold">
                                                {{ $cell['delta'] }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        @endforeach

                        {{-- Group cell --}}
                        @if ($reportData->group !== null)
                            @php
                                $groupCell = $this->formatCell($reportData->group, $key, $def);
                            @endphp
                            <td class="px-4 py-3 text-gray-900 dark:text-white bg-gray-50/60 dark:bg-white/5 font-semibold">
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold">{{ $groupCell['primary'] }}</span>
                                    <div class="flex items-center gap-2 text-[11px] text-gray-500 dark:text-gray-400">
                                        @if ($groupCell['pctOfSales'] !== null)
                                            <span>{{ $groupCell['pctOfSales'] }}</span>
                                        @endif
                                        @if ($groupCell['delta'] !== null)
                                            <span class="{{ $groupCell['deltaColor'] }} font-semibold">
                                                {{ $groupCell['delta'] }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                            لا توجد بيانات متاحة
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament::section>
