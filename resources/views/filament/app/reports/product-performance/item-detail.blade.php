@props(['detail'])

@if (! $detail)
    <div class="p-6 text-center text-gray-500 dark:text-gray-400">
        لا توجد بيانات تفصيلية لهذا الصنف
    </div>
@else
    <div class="space-y-6 p-4">
        {{-- Header / Summary stats --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="rounded-xl border border-gray-200 bg-gray-50/50 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="text-xs text-gray-500 dark:text-gray-400">الكمية المباعة</span>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                    {{ \App\Support\Format::number($detail->totalQtySold, 0) }} {{ $detail->unit }}
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50/50 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="text-xs text-gray-500 dark:text-gray-400">صافي المبيعات</span>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                    {{ \App\Support\Format::money($detail->totalNetSales, 2) }}
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50/50 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="text-xs text-gray-500 dark:text-gray-400">مجمل الربح</span>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                    {{ \App\Support\Format::money($detail->totalGrossProfit, 2) }}
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-gray-50/50 p-4 dark:border-white/10 dark:bg-white/5">
                <span class="text-xs text-gray-500 dark:text-gray-400">هامش الربح</span>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                    {{ \App\Support\Format::percent($detail->marginPct, 1) }}
                </p>
            </div>
        </div>

        {{-- Quality / Waste KPIs --}}
        <div class="grid grid-cols-3 gap-4">
            <div class="rounded-xl border border-amber-200/50 bg-amber-50/30 p-3 dark:border-amber-500/20 dark:bg-amber-500/5">
                <span class="text-xs text-amber-700 dark:text-amber-400">كمية المرتجع</span>
                <p class="mt-1 font-semibold text-amber-900 dark:text-amber-200">
                    {{ \App\Support\Format::number($detail->totalReturnsQty, 1) }} {{ $detail->unit }}
                </p>
            </div>
            <div class="rounded-xl border border-rose-200/50 bg-rose-50/30 p-3 dark:border-rose-500/20 dark:bg-rose-500/5">
                <span class="text-xs text-rose-700 dark:text-rose-400">كمية الهالك</span>
                <p class="mt-1 font-semibold text-rose-900 dark:text-rose-200">
                    {{ \App\Support\Format::number($detail->totalWasteQty, 1) }} {{ $detail->unit }}
                </p>
            </div>
            <div class="rounded-xl border border-rose-200/50 bg-rose-50/30 p-3 dark:border-rose-500/20 dark:bg-rose-500/5">
                <span class="text-xs text-rose-700 dark:text-rose-400">تكلفة الهالك</span>
                <p class="mt-1 font-semibold text-rose-900 dark:text-rose-200">
                    {{ \App\Support\Format::money($detail->totalWasteCost, 2) }}
                </p>
            </div>
        </div>

        {{-- Daily Trend Table --}}
        <div>
            <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">حركة المبيعات والتكلفة اليومية</h4>
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-start text-xs divide-y divide-gray-200 dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">التاريخ</th>
                            <th class="px-3 py-2 text-start font-medium text-gray-700 dark:text-gray-300">الفرع</th>
                            <th class="px-3 py-2 text-end font-medium text-gray-700 dark:text-gray-300">الكمية</th>
                            <th class="px-3 py-2 text-end font-medium text-gray-700 dark:text-gray-300">المبيعات</th>
                            <th class="px-3 py-2 text-end font-medium text-gray-700 dark:text-gray-300">سعر البيع</th>
                            <th class="px-3 py-2 text-end font-medium text-gray-700 dark:text-gray-300">تكلفة الوحدة</th>
                            <th class="px-3 py-2 text-end font-medium text-gray-700 dark:text-gray-300">الهامش</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach ($detail->dailyRows as $row)
                            <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5">
                                <td class="px-3 py-2 font-mono text-gray-900 dark:text-white">{{ $row->businessDate }}</td>
                                <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $row->branchName }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ \App\Support\Format::number($row->qtySold, 0) }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ \App\Support\Format::money($row->netSales, 2) }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ \App\Support\Format::money($row->price, 2) }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ \App\Support\Format::money($row->unitCost, 2) }}</td>
                                <td class="px-3 py-2 text-end font-mono">{{ \App\Support\Format::percent($row->marginPct, 1) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
