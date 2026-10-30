<x-filament::section>
    <x-slot name="heading">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <span class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $heading }}
                </span>
                @if ($currentBranchName)
                    <span class="text-xs text-gray-500 dark:text-gray-400 font-normal">
                        ({{ $currentBranchName }})
                    </span>
                @endif
            </div>

            {{-- Controls Toolbar --}}
            <div class="flex flex-wrap items-center gap-3 text-xs">
                {{-- Branch Selector (if multiple branches) --}}
                @if (count($availableBranches) > 1)
                    <div class="flex items-center gap-1.5">
                        <label for="heatmap-branch" class="text-gray-500 dark:text-gray-400 font-medium">الفرع:</label>
                        <select
                            id="heatmap-branch"
                            wire:change="setBranch($event.target.value)"
                            class="text-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-gray-800 text-gray-800 dark:text-gray-200 py-1 px-2.5 focus:ring-primary-500 focus:border-primary-500">
                            @foreach ($availableBranches as $bId => $bName)
                                <option value="{{ $bId }}" @selected($selectedBranchId == $bId)>{{ $bName }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Metric Toggle: Orders vs Sales --}}
                <div class="inline-flex rounded-lg border border-gray-200 dark:border-white/10 p-0.5 bg-gray-50 dark:bg-white/5">
                    <button
                        type="button"
                        wire:click="setMetric('orders')"
                        @class([
                            'px-2.5 py-1 rounded-md font-medium transition',
                            'bg-white dark:bg-white/10 text-primary-600 dark:text-primary-400 shadow-sm' => $metric === 'orders',
                            'text-gray-600 dark:text-gray-300 hover:text-gray-900' => $metric !== 'orders',
                        ])>
                        متوسط الطلبات
                    </button>
                    <button
                        type="button"
                        wire:click="setMetric('sales')"
                        @class([
                            'px-2.5 py-1 rounded-md font-medium transition',
                            'bg-white dark:bg-white/10 text-primary-600 dark:text-primary-400 shadow-sm' => $metric === 'sales',
                            'text-gray-600 dark:text-gray-300 hover:text-gray-900' => $metric !== 'sales',
                        ])>
                        متوسط المبيعات
                    </button>
                </div>

                {{-- Business Day Extended Hours Toggle (if >= 24h exist) --}}
                @if ($hasExtendedHours)
                    <div class="inline-flex rounded-lg border border-gray-200 dark:border-white/10 p-0.5 bg-gray-50 dark:bg-white/5">
                        <button
                            type="button"
                            wire:click="setMode('standard')"
                            @class([
                                'px-2 py-1 rounded-md font-medium transition',
                                'bg-white dark:bg-white/10 text-primary-600 dark:text-primary-400 shadow-sm' => $mode === 'standard',
                                'text-gray-600 dark:text-gray-300 hover:text-gray-900' => $mode !== 'standard',
                            ])>
                            ساعات عادية (0–23)
                        </button>
                        <button
                            type="button"
                            wire:click="setMode('extended')"
                            @class([
                                'px-2 py-1 rounded-md font-medium transition',
                                'bg-white dark:bg-white/10 text-primary-600 dark:text-primary-400 shadow-sm' => $mode === 'extended',
                                'text-gray-600 dark:text-gray-300 hover:text-gray-900' => $mode !== 'extended',
                            ])>
                            ساعات يوم العمل (24+)
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </x-slot>

    @if (! $heatmapData || empty($displayHours))
        <div class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">
            لا توجد بيانات ساعات متوفرة لهذا الفرع في الفترة المحددة
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-center text-xs border-collapse">
                <thead>
                    <tr class="bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10">
                        <th class="px-3 py-2.5 text-start font-semibold text-gray-700 dark:text-gray-300 min-w-20">
                            الساعة
                        </th>
                        @foreach ($weekdayLabels as $wIdx => $wLabel)
                            <th class="px-2 py-2.5 font-semibold text-gray-700 dark:text-gray-300 min-w-16">
                                {{ $wLabel }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5 font-mono">
                    @foreach ($displayHours as $h)
                        <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5 transition">
                            <td class="px-3 py-2 text-start font-medium text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                @if ($h >= 24)
                                    {{ sprintf('%02d:00 (+%d)', $h % 24, intdiv($h, 24)) }}
                                @else
                                    {{ sprintf('%02d:00', $h) }}
                                @endif
                            </td>
                            @foreach ($weekdays as $w)
                                @php
                                    $val = $metric === 'orders'
                                        ? ($heatmapData->matrixOrders[$h][$w] ?? 0.0)
                                        : ($heatmapData->matrixSales[$h][$w] ?? 0.0);
                                    $maxVal = $metric === 'orders' ? $heatmapData->maxOrders : $heatmapData->maxSales;
                                    $ratio = $maxVal > 0 ? ($val / $maxVal) : 0;
                                    $hasVal = $val > 0.0;
                                @endphp
                                <td
                                    class="px-2 py-2 transition text-center relative"
                                    style="{{ $hasVal ? 'background-color: rgba(249, 115, 22, ' . max(0.12, min(0.85, $ratio)) . ');' : '' }}"
                                    title="{{ $weekdayLabels[$w] }} {{ sprintf('%02d:00', $h) }}: {{ $metric === 'orders' ? $val . ' طلب' : number_format($val, 2) . ' ج.م' }}">
                                    @if ($hasVal)
                                        <span class="{{ $ratio > 0.5 ? 'font-bold text-gray-950 dark:text-white' : 'text-gray-800 dark:text-gray-200' }}">
                                            @if ($metric === 'orders')
                                                {{ number_format($val, 1) }}
                                            @else
                                                {{ number_format($val, 0) }}
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between text-xs text-gray-500 dark:text-gray-400 px-1">
            <div class="flex items-center gap-2">
                <span>تدرج الكثافة:</span>
                <span class="inline-block w-4 h-3 rounded" style="background-color: rgba(249, 115, 22, 0.15)"></span>
                <span>منخفض</span>
                <span class="inline-block w-4 h-3 rounded" style="background-color: rgba(249, 115, 22, 0.50)"></span>
                <span>متوسط</span>
                <span class="inline-block w-4 h-3 rounded" style="background-color: rgba(249, 115, 22, 0.85)"></span>
                <span>ذروة</span>
            </div>
            <div>
                <span>* القيم محسوبة كمتوسط على عدد الأيام التي توفرت بها بيانات</span>
            </div>
        </div>
    @endif
</x-filament::section>
