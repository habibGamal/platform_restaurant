<x-filament::section>
    <x-slot name="heading">
        <div class="flex items-center justify-between w-full">
            <span class="text-base font-semibold text-gray-900 dark:text-white">
                حالة مزامنة واكتمال البيانات
            </span>
            <a
                href="{{ url('/app/reports/data-health') }}"
                class="text-xs font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
            >
                تقرير صحة البيانات &larr;
            </a>
        </div>
    </x-slot>

    <div class="space-y-4">
        <div class="p-3 bg-gray-50 dark:bg-white/5 rounded-lg flex items-center justify-between">
            <x-reports.coverage :complete="$summary['complete']" :total="$summary['total']" />
            <span class="text-xs text-gray-500 dark:text-gray-400">
                تحديث حي
            </span>
        </div>

        <div class="divide-y divide-gray-100 dark:divide-white/5 max-h-64 overflow-y-auto">
            @forelse ($branches as $branch)
                <div class="py-2.5 flex items-center justify-between text-sm">
                    <div class="flex items-center gap-2">
                        @if ($branch['status'] === 'online')
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500" title="متصل"></span>
                        @elseif ($branch['status'] === 'delayed')
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse" title="متأخر"></span>
                        @else
                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-rose-500" title="غير متصل"></span>
                        @endif

                        <span class="font-medium text-gray-900 dark:text-white">
                            {{ $branch['name'] }}
                        </span>
                    </div>

                    <div class="flex items-center gap-3 text-xs">
                        @if ($branch['last_sync'])
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $branch['last_sync'] }}
                            </span>
                        @else
                            <span class="text-rose-500 dark:text-rose-400 font-medium">
                                لا توجد مزامنة
                            </span>
                        @endif

                        @if ($branch['status'] === 'online')
                            <span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 font-medium">
                                متصل
                            </span>
                        @elseif ($branch['status'] === 'delayed')
                            <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400 font-medium">
                                متأخر (&gt; 15 دقيقة)
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-400 font-medium">
                                غير متصل
                            </span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                    لا توجد فروع محددة
                </div>
            @endforelse
        </div>
    </div>
</x-filament::section>
