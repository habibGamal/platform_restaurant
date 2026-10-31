<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-gray-100 dark:border-white/10">
            <div>
                <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    {{ $heading ?? 'تقويم اكتمال وجودة البيانات' }}
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    حالة اكتمال إغلاق الأيام ومطابقتها مع ملخصات نقاط البيع (POS) عبر الفروع
                </p>
            </div>

            <div class="flex items-center gap-3">
                <x-reports.coverage :complete="$coverage['complete'] ?? 0" :total="$coverage['total'] ?? 0" />
            </div>
        </div>

        {{-- Legend --}}
        <div class="flex flex-wrap items-center gap-4 py-3 text-xs text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-white/5">
            <span class="font-medium text-gray-800 dark:text-gray-200">الدليل:</span>
            <span class="inline-flex items-center gap-1.5">
                <x-reports.completeness-dot status="complete" />
                <span>مكتمل ومطابق</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-reports.completeness-dot status="closed" />
                <span>مغلق بدون مطابقة</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-reports.completeness-dot status="open" />
                <span>مباشر (مفتوح)</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-reports.completeness-dot status="missing" />
                <span>بيانات ناقصة</span>
            </span>
            <span class="inline-flex items-center gap-1.5">
                <x-reports.completeness-dot status="not_opened" />
                <span>قبل الافتتاح</span>
            </span>
        </div>

        {{-- Calendar Table --}}
        <div class="overflow-x-auto mt-4 rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full text-start text-xs divide-y divide-gray-200 dark:divide-white/10">
                <thead class="bg-gray-50/80 dark:bg-white/5 text-gray-700 dark:text-gray-300">
                    <tr>
                        <th scope="col" class="sticky start-0 z-10 bg-gray-50 dark:bg-gray-900 px-3 py-2.5 text-start font-semibold min-w-[140px] shadow-sm">
                            الفرع
                        </th>
                        <th scope="col" class="px-2 py-2.5 text-center font-semibold min-w-[70px]">
                            الإنجاز
                        </th>
                        @foreach ($dates as $date)
                            @php
                                $dateObj = \Carbon\CarbonImmutable::parse($date);
                                $formattedDate = $dateObj->format('d/m');
                                $dayName = match ($dateObj->dayOfWeek) {
                                    6 => 'السبت',
                                    0 => 'الأحد',
                                    1 => 'الاثنين',
                                    2 => 'الثلاثاء',
                                    3 => 'الأربعاء',
                                    4 => 'الخميس',
                                    5 => 'الجمعة',
                                    default => '',
                                };
                            @endphp
                            <th scope="col" class="px-2 py-2 text-center min-w-[44px]" title="{{ $dayName }} {{ $date }}">
                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ $formattedDate }}</div>
                                <div class="text-[10px] text-gray-400 font-normal">{{ mb_substr($dayName, 0, 2) }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse ($branches as $branchRow)
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5 transition">
                            <td class="sticky start-0 z-10 bg-white dark:bg-gray-900 px-3 py-3 text-start font-semibold text-gray-900 dark:text-white shadow-sm whitespace-nowrap">
                                {{ $branchRow->branchName }}
                            </td>
                            <td class="px-2 py-3 text-center whitespace-nowrap">
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium {{ $branchRow->completeDays === $branchRow->totalDays && $branchRow->totalDays > 0 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400' }}">
                                    {{ $branchRow->completeDays }}/{{ $branchRow->totalDays }}
                                </span>
                            </td>
                            @foreach ($dates as $date)
                                @php
                                    $cell = $branchRow->days[$date] ?? null;
                                    $status = $cell?->status ?? 'missing';
                                @endphp
                                <td class="px-2 py-3 text-center whitespace-nowrap" title="{{ $branchRow->branchName }} - {{ $date }}">
                                    <div class="flex items-center justify-center p-1">
                                        <x-reports.completeness-dot :status="$status" />
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($dates) + 2 }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                لا توجد فروع متاحة لعرض التقويم
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
