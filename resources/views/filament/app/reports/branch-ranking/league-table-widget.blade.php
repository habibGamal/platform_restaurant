<x-filament-widgets::widget class="fi-wi-table">
    <x-filament::section heading="جدول ترتيب الفروع (League Table)">
        @if ($singleBranchMode)
            <div class="mb-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/40 text-xs text-amber-800 dark:text-amber-300">
                وضع الفرع الواحد: يتم عرض مؤشرات الأداء الخاصة بالفرع فقط دون حساب ترتيب عام.
            </div>
        @endif

        {{ $this->table }}

        {{-- Excluded Branches Section --}}
        @if (! empty($excludedBranches))
            <div class="mt-6 pt-4 border-t border-gray-200 dark:border-white/10">
                <div class="flex items-center gap-2 mb-3">
                    <span class="text-rose-500">
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="w-4 h-4" />
                    </span>
                    <h4 class="font-bold text-sm text-gray-800 dark:text-gray-200">
                        فروع مستبعدة لنقص البيانات ({{ count($excludedBranches) }})
                    </h4>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach ($excludedBranches as $excluded)
                        <div class="p-3 rounded-lg border border-rose-200/70 dark:border-rose-900/40 bg-rose-50/40 dark:bg-rose-950/10 flex items-center justify-between">
                            <div>
                                <div class="font-bold text-xs text-gray-900 dark:text-white">{{ $excluded->branchName }}</div>
                                <div class="text-[11px] text-rose-600 dark:text-rose-400 mt-0.5">{{ $excluded->reason }}</div>
                            </div>
                            <div class="text-end">
                                <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-white dark:bg-white/10 text-gray-700 dark:text-gray-300">
                                    {{ $excluded->completeDays }} / {{ $excluded->totalDays }} يوم مكتمل
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
