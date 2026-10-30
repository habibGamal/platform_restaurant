<x-filament::section heading="منصة أداء الفروع (Podium)">
    @if ($singleBranchMode)
        <div class="p-4 rounded-xl bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10 text-center">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                وضع الفرع الواحد: يتم عرض المؤشرات والبيانات دون ترتيب أو منصة مقارنة.
            </p>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Top 3 Section --}}
            <div class="rounded-xl border border-emerald-200 dark:border-emerald-800/40 bg-emerald-50/30 dark:bg-emerald-950/20 p-5">
                <div class="flex items-center gap-2 mb-4">
                    <span class="p-2 rounded-lg bg-emerald-500 text-white shadow-sm">
                        <x-filament::icon icon="heroicon-o-trophy" class="w-5 h-5" />
                    </span>
                    <div>
                        <h3 class="font-bold text-gray-900 dark:text-white text-base">أفضل 3 فروع أداءً</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">الفروع المتصدرة وفق النتيجة الإجمالية الموزونة</p>
                    </div>
                </div>

                <div class="space-y-3">
                    @forelse ($top3 as $branch)
                        <div class="flex items-center justify-between p-3.5 rounded-lg bg-white dark:bg-white/5 border border-emerald-100 dark:border-emerald-900/30 shadow-sm">
                            <div class="flex items-center gap-3">
                                <span class="flex items-center justify-center w-8 h-8 rounded-full font-bold text-sm
                                    {{ $branch->rank === 1 ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300 ring-2 ring-amber-400' :
                                       ($branch->rank === 2 ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 ring-2 ring-slate-300' :
                                       'bg-amber-800/20 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200 ring-2 ring-amber-700/40') }}">
                                    #{{ $branch->rank }}
                                </span>
                                <div>
                                    <div class="font-bold text-gray-900 dark:text-white text-sm flex items-center gap-1.5">
                                        {{ $branch->branchName }}
                                        @if ($branch->isCurrentManagerBranch)
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-300 font-semibold">فرعك</span>
                                        @endif
                                    </div>
                                    @if (! empty($branch->helpedKpis))
                                        <div class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-0.5 flex flex-wrap gap-1">
                                            <span>أبرز القوى:</span>
                                            @foreach ($branch->helpedKpis as $kpi)
                                                <span class="inline-flex items-center px-1.5 py-0.2 rounded bg-emerald-100/60 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300">
                                                    {{ $kpi->kpiLabel }} ({{ round($kpi->score ?? 0, 1) }})
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="text-base font-extrabold text-emerald-700 dark:text-emerald-400">
                                    {{ number_format($branch->overallScore ?? 0, 1) }}
                                    <span class="text-[10px] font-normal text-gray-500">/ 100</span>
                                </div>
                                <div class="text-[11px] font-semibold text-{{ $branch->rankDeltaColor }}-600">
                                    {{ $branch->rankDeltaFormatted }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-500 py-3 text-center">لا توجد بيانات كافية</p>
                    @endforelse
                </div>
            </div>

            {{-- Bottom 3 Section --}}
            <div class="rounded-xl border border-rose-200 dark:border-rose-800/40 bg-rose-50/30 dark:bg-rose-950/20 p-5">
                <div class="flex items-center gap-2 mb-4">
                    <span class="p-2 rounded-lg bg-rose-500 text-white shadow-sm">
                        <x-filament::icon icon="heroicon-o-arrow-trending-down" class="w-5 h-5" />
                    </span>
                    <div>
                        <h3 class="font-bold text-gray-900 dark:text-white text-base">أقل 3 فروع أداءً</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">الفروع التي تتطلب متابعة في مؤشرات الأداء الحرجة</p>
                    </div>
                </div>

                <div class="space-y-3">
                    @forelse ($bottom3 as $branch)
                        <div class="flex items-center justify-between p-3.5 rounded-lg bg-white dark:bg-white/5 border border-rose-100 dark:border-rose-900/30 shadow-sm">
                            <div class="flex items-center gap-3">
                                <span class="flex items-center justify-center w-8 h-8 rounded-full font-bold text-sm bg-rose-100 text-rose-800 dark:bg-rose-900/50 dark:text-rose-300 ring-2 ring-rose-300">
                                    #{{ $branch->rank }}
                                </span>
                                <div>
                                    <div class="font-bold text-gray-900 dark:text-white text-sm flex items-center gap-1.5">
                                        {{ $branch->branchName }}
                                        @if ($branch->isCurrentManagerBranch)
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-300 font-semibold">فرعك</span>
                                        @endif
                                    </div>
                                    @if (! empty($branch->hurtKpis))
                                        <div class="text-[11px] text-rose-600 dark:text-rose-400 mt-0.5 flex flex-wrap gap-1">
                                            <span>نقاط التحسين:</span>
                                            @foreach ($branch->hurtKpis as $kpi)
                                                <span class="inline-flex items-center px-1.5 py-0.2 rounded bg-rose-100/60 dark:bg-rose-900/40 text-rose-800 dark:text-rose-300">
                                                    {{ $kpi->kpiLabel }} ({{ round($kpi->score ?? 0, 1) }})
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="text-base font-extrabold text-rose-700 dark:text-rose-400">
                                    {{ number_format($branch->overallScore ?? 0, 1) }}
                                    <span class="text-[10px] font-normal text-gray-500">/ 100</span>
                                </div>
                                <div class="text-[11px] font-semibold text-{{ $branch->rankDeltaColor }}-600">
                                    {{ $branch->rankDeltaFormatted }}
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="p-4 rounded-lg bg-white/50 dark:bg-white/5 text-center text-xs text-gray-500">
                            لا توجد فروع كافية لعرض قائمة الأقل أداءً (تتطلب 4 فروع على الأقل)
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</x-filament::section>
