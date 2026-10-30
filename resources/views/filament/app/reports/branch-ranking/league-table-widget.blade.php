<x-filament::section heading="جدول ترتيب الفروع (League Table)">
    @if ($singleBranchMode)
        <div class="mb-4 p-3 rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/40 text-xs text-amber-800 dark:text-amber-300">
            وضع الفرع الواحد: يتم عرض مؤشرات الأداء الخاصة بالفرع فقط دون حساب ترتيب عام.
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
        <table class="w-full text-start text-xs divide-y divide-gray-200 dark:divide-white/10">
            <thead class="bg-gray-50/75 dark:bg-white/5">
                <tr>
                    <th scope="col" class="px-3 py-3 text-start font-bold text-gray-900 dark:text-white">الترتيب</th>
                    <th scope="col" class="px-2 py-3 text-center font-bold text-gray-900 dark:text-white">التغيير</th>
                    <th scope="col" class="px-4 py-3 text-start font-bold text-gray-900 dark:text-white">الفرع</th>
                    <th scope="col" class="px-3 py-3 text-center font-bold text-gray-900 dark:text-white">النتيجة</th>

                    <th scope="col" class="px-3 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">
                        هامش الربح <span class="text-[10px] text-gray-400">({{ $weights['net_margin'] ?? 25 }}%)</span>
                    </th>
                    <th scope="col" class="px-3 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">
                        نمو المبيعات <span class="text-[10px] text-gray-400">({{ $weights['sales_growth'] ?? 20 }}%)</span>
                    </th>
                    <th scope="col" class="px-3 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">
                        تحقيق المستهدف <span class="text-[10px] text-gray-400">({{ $weights['target_achievement'] ?? 20 }}%)</span>
                    </th>
                    <th scope="col" class="px-3 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">
                        تكلفة الخامات <span class="text-[10px] text-gray-400">({{ $weights['food_cost_pct'] ?? 15 }}%)</span>
                    </th>
                    <th scope="col" class="px-3 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">
                        الهالك <span class="text-[10px] text-gray-400">({{ $weights['waste_pct'] ?? 10 }}%)</span>
                    </th>
                    <th scope="col" class="px-3 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">
                        نمو متوسط الطلب <span class="text-[10px] text-gray-400">({{ $weights['avg_ticket_growth'] ?? 5 }}%)</span>
                    </th>
                    <th scope="col" class="px-3 py-3 text-center font-semibold text-gray-700 dark:text-gray-300">
                        نسبة الإلغاء <span class="text-[10px] text-gray-400">({{ $weights['cancel_rate'] ?? 5 }}%)</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10 bg-white dark:bg-white/5">
                @forelse ($rankedBranches as $row)
                    <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5 transition {{ $row->isCurrentManagerBranch ? 'bg-primary-50/20 dark:bg-primary-950/20' : '' }}">
                        {{-- Rank --}}
                        <td class="px-3 py-3 whitespace-nowrap font-extrabold text-sm text-gray-900 dark:text-white">
                            @if ($row->rank !== null)
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full
                                    {{ $row->rank === 1 ? 'bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-200 font-black' :
                                       ($row->rank === 2 ? 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-200' :
                                       ($row->rank === 3 ? 'bg-amber-800/10 text-amber-900 dark:bg-amber-950/40 dark:text-amber-300' : 'text-gray-700 dark:text-gray-300')) }}">
                                    #{{ $row->rank }}
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>

                        {{-- Rank Delta --}}
                        <td class="px-2 py-3 whitespace-nowrap text-center font-bold">
                            @if ($row->rankDeltaColor === 'success')
                                <span class="text-emerald-600 dark:text-emerald-400">{{ $row->rankDeltaFormatted }}</span>
                            @elseif ($row->rankDeltaColor === 'danger')
                                <span class="text-rose-600 dark:text-rose-400">{{ $row->rankDeltaFormatted }}</span>
                            @else
                                <span class="text-gray-400">{{ $row->rankDeltaFormatted }}</span>
                            @endif
                        </td>

                        {{-- Branch Name --}}
                        <td class="px-4 py-3 whitespace-nowrap font-bold text-gray-900 dark:text-white">
                            <div class="flex items-center gap-1.5">
                                <span>{{ $row->branchName }}</span>
                                @if ($row->isCurrentManagerBranch)
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-300 font-semibold">فرعك</span>
                                @endif
                                @if ($row->hasUnavailableKpi)
                                    <span title="تمت إعادة توزيع أوزان المؤشرات غير المتوفرة لهذا الفرع" class="text-amber-500 cursor-help">
                                        <x-filament::icon icon="heroicon-m-information-circle" class="w-3.5 h-3.5" />
                                    </span>
                                @endif
                            </div>
                        </td>

                        {{-- Overall Score --}}
                        <td class="px-3 py-3 whitespace-nowrap text-center font-black text-sm">
                            @if ($row->overallScore !== null)
                                <span class="px-2 py-1 rounded-lg {{ $row->overallScore >= 70 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : ($row->overallScore >= 40 ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300') }}">
                                    {{ number_format($row->overallScore, 1) }}
                                </span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>

                        {{-- KPI columns --}}
                        @php
                            $kpiKeys = ['net_margin', 'sales_growth', 'target_achievement', 'food_cost_pct', 'waste_pct', 'avg_ticket_growth', 'cancel_rate'];
                        @endphp

                        @foreach ($kpiKeys as $kKey)
                            @php
                                $kpi = $row->kpiScores[$kKey] ?? null;
                                $score = $kpi?->score;
                            @endphp
                            <td class="px-2.5 py-2.5 whitespace-nowrap text-center">
                                @if ($kpi && $kpi->rawValue !== null)
                                    <div class="font-medium text-gray-900 dark:text-white">
                                        {{ $kpi->formattedValue }}
                                    </div>
                                    @if ($score !== null)
                                        <div class="mt-0.5">
                                            <span class="inline-block text-[10px] px-1.5 py-0.2 rounded font-bold
                                                {{ $score >= 70 ? 'bg-emerald-100/70 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' :
                                                   ($score >= 40 ? 'bg-amber-100/70 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' :
                                                   'bg-rose-100/70 text-rose-800 dark:bg-rose-900/40 dark:text-rose-300') }}">
                                                {{ round($score, 1) }}
                                            </span>
                                        </div>
                                    @endif
                                @else
                                    <span class="text-gray-400" title="غير متوفر">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="px-4 py-8 text-center text-gray-500">
                            لا توجد فروع مؤهلة للترتيب في هذه الفترة
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

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
