<x-filament::section heading="رواد المؤشرات (Per-KPI Leaders)">
    @if ($singleBranchMode && $singleBranchRow)
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach ($singleBranchRow->kpiScores as $kpiKey => $kpi)
                <div class="p-4 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 shadow-sm">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">
                        {{ $kpi->kpiLabel }}
                    </div>
                    <div class="text-lg font-bold text-gray-900 dark:text-white">
                        {{ $kpi->formattedValue }}
                    </div>
                    <div class="text-[11px] text-gray-400 mt-1">
                        الوزن في التقييم: {{ $kpi->weight }}%
                    </div>
                </div>
            @endforeach
        </div>
    @elseif (! empty($leaders))
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach ($leaders as $leader)
                <div class="p-4 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-3 border-b border-gray-100 dark:border-white/5 pb-2">
                        <span class="font-bold text-sm text-gray-900 dark:text-white">{{ $leader->kpiLabel }}</span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded {{ $leader->direction === 'higher' ? 'bg-blue-50 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300' : 'bg-purple-50 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300' }}">
                            {{ $leader->direction === 'higher' ? 'الأعلى أفضل' : 'الأقل أفضل' }}
                        </span>
                    </div>

                    <div class="space-y-2.5">
                        {{-- Best Branch --}}
                        <div class="flex items-center justify-between p-2 rounded-lg bg-emerald-50/60 dark:bg-emerald-950/20 border border-emerald-100 dark:border-emerald-900/30">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <span class="text-emerald-600 dark:text-emerald-400">
                                    <x-filament::icon icon="heroicon-m-arrow-up-circle" class="w-4 h-4" />
                                </span>
                                <div class="truncate">
                                    <div class="text-[10px] text-emerald-800 dark:text-emerald-300 font-semibold">الأفضل أداءً</div>
                                    <div class="text-xs font-bold text-gray-900 dark:text-white truncate">{{ $leader->bestBranchName }}</div>
                                </div>
                            </div>
                            <span class="font-bold text-xs text-emerald-700 dark:text-emerald-400 whitespace-nowrap ps-2">
                                {{ $leader->bestFormatted }}
                            </span>
                        </div>

                        {{-- Worst Branch --}}
                        <div class="flex items-center justify-between p-2 rounded-lg bg-rose-50/60 dark:bg-rose-950/20 border border-rose-100 dark:border-rose-900/30">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <span class="text-rose-600 dark:text-rose-400">
                                    <x-filament::icon icon="heroicon-m-arrow-down-circle" class="w-4 h-4" />
                                </span>
                                <div class="truncate">
                                    <div class="text-[10px] text-rose-800 dark:text-rose-300 font-semibold">الأقل أداءً</div>
                                    <div class="text-xs font-bold text-gray-900 dark:text-white truncate">{{ $leader->worstBranchName }}</div>
                                </div>
                            </div>
                            <span class="font-bold text-xs text-rose-700 dark:text-rose-400 whitespace-nowrap ps-2">
                                {{ $leader->worstFormatted }}
                            </span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="p-4 rounded-xl bg-gray-50 dark:bg-white/5 text-center text-sm text-gray-500">
            لا توجد بيانات كافية لعرض رواد المؤشرات
        </div>
    @endif
</x-filament::section>
