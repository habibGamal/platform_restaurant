<x-filament::section>
    <x-slot name="heading">
        <div class="flex items-center justify-between gap-4">
            <div>
                <span class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $heading ?? 'مصفوفة المصروفات حسب التصنيف والفروع' }}
                </span>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    توزيع المصروفات التشغيلية لكل تصنيف على الفروع مع نسبة كل بند من مبيعات الفرع
                </p>
            </div>
        </div>
    </x-slot>

    <div class="overflow-x-auto -mx-6 -mb-6">
        <table class="w-full text-right text-sm border-t border-gray-200 dark:border-white/10 divide-y divide-gray-200 dark:divide-white/10">
            <thead class="bg-gray-50/75 dark:bg-white/5 text-xs text-gray-600 dark:text-gray-400 font-semibold">
                <tr>
                    <th scope="col" class="py-3 px-4 min-w-[180px] sticky right-0 z-10 bg-gray-50 dark:bg-gray-900 border-l border-gray-200 dark:border-white/10">
                        التصنيف
                    </th>
                    @foreach ($matrix->branches as $branch)
                        <th scope="col" class="py-3 px-4 min-w-[140px] text-center border-l border-gray-200 dark:border-white/10">
                            <div>{{ $branch['name'] }}</div>
                            <div class="text-[11px] font-normal text-gray-400 dark:text-gray-500">
                                مبيعات: {{ \App\Support\Format::money($branch['sales']) }}
                            </div>
                        </th>
                    @endforeach
                    @if ($matrix->grandTotal !== null)
                        <th scope="col" class="py-3 px-4 min-w-[150px] text-center bg-primary-50/50 dark:bg-primary-950/20 font-bold text-primary-950 dark:text-primary-200">
                            <div>إجمالي الفروع</div>
                            <div class="text-[11px] font-normal text-primary-600/70 dark:text-primary-400/70">
                                المجموعة
                            </div>
                        </th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                @foreach ($matrix->categories as $category)
                    <tr class="hover:bg-gray-50/50 dark:hover:bg-white/[0.02] transition">
                        <td class="py-2.5 px-4 font-medium text-gray-900 dark:text-white sticky right-0 bg-white dark:bg-gray-900 border-l border-gray-200 dark:border-white/10">
                            <div class="flex items-center gap-2">
                                <span>{{ $matrix->categoryLabels[$category] ?? $category }}</span>
                                @if ($category === 'labor')
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">K11a</span>
                                @endif
                            </div>
                        </td>
                        @foreach ($matrix->branches as $branch)
                            @php
                                $cell = $matrix->cells[$category][$branch['id']] ?? ['amount' => 0.0, 'percentOfSales' => null];
                            @endphp
                            <td class="py-2.5 px-4 text-center border-l border-gray-200 dark:border-white/10">
                                <div class="font-medium text-gray-900 dark:text-white">
                                    {{ \App\Support\Format::money($cell['amount']) }}
                                </div>
                                <div class="text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ \App\Support\Format::percent($cell['percentOfSales']) }}
                                </div>
                            </td>
                        @endforeach
                        @if ($matrix->grandTotal !== null)
                            @php
                                $catTotal = $matrix->categoryTotals[$category] ?? ['amount' => 0.0, 'percentOfSales' => null];
                            @endphp
                            <td class="py-2.5 px-4 text-center bg-primary-50/20 dark:bg-primary-950/10 font-semibold">
                                <div class="text-primary-950 dark:text-primary-200">
                                    {{ \App\Support\Format::money($catTotal['amount']) }}
                                </div>
                                <div class="text-[11px] text-primary-600/80 dark:text-primary-400/80">
                                    {{ \App\Support\Format::percent($catTotal['percentOfSales']) }}
                                </div>
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="bg-gray-100/75 dark:bg-white/10 font-bold border-t-2 border-gray-300 dark:border-white/20">
                <tr>
                    <td class="py-3 px-4 text-gray-950 dark:text-white sticky right-0 bg-gray-100 dark:bg-gray-800 border-l border-gray-300 dark:border-white/20">
                        <div class="flex items-center gap-2">
                            <span>إجمالي المصروفات</span>
                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-200">K11</span>
                        </div>
                    </td>
                    @foreach ($matrix->branches as $branch)
                        @php
                            $bTotal = $matrix->branchTotals[$branch['id']] ?? ['amount' => 0.0, 'percentOfSales' => null];
                        @endphp
                        <td class="py-3 px-4 text-center border-l border-gray-300 dark:border-white/20">
                            <div class="text-gray-950 dark:text-white">
                                {{ \App\Support\Format::money($bTotal['amount']) }}
                            </div>
                            <div class="text-xs text-gray-600 dark:text-gray-300">
                                {{ \App\Support\Format::percent($bTotal['percentOfSales']) }}
                            </div>
                        </td>
                    @endforeach
                    @if ($matrix->grandTotal !== null)
                        <td class="py-3 px-4 text-center bg-primary-100/60 dark:bg-primary-900/40 text-primary-950 dark:text-primary-100">
                            <div>
                                {{ \App\Support\Format::money($matrix->grandTotal['amount']) }}
                            </div>
                            <div class="text-xs text-primary-700 dark:text-primary-300">
                                {{ \App\Support\Format::percent($matrix->grandTotal['percentOfSales']) }}
                            </div>
                        </td>
                    @endif
                </tr>
            </tfoot>
        </table>
    </div>
</x-filament::section>
