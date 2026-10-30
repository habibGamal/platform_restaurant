<x-filament::section>
    <x-slot name="heading">
        <div class="flex items-center justify-between w-full">
            <div>
                <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    مصفوفة مقارنة مبيعات الأصناف بين الفروع
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                    معدل بيع كل صنف يومياً لكل فرع مفتوح (يوضح الأصناف الأكثر طلباً حسب الفرع)
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    wire:click="setMetric('qty')"
                    class="px-2.5 py-1 text-xs font-medium rounded-lg transition {{ $metric === 'qty' ? 'bg-primary-600 text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300' }}"
                >
                    الكمية / يوم
                </button>
                <button
                    type="button"
                    wire:click="setMetric('sales')"
                    class="px-2.5 py-1 text-xs font-medium rounded-lg transition {{ $metric === 'sales' ? 'bg-primary-600 text-white shadow-sm' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300' }}"
                >
                    المبيعات / يوم
                </button>
            </div>
        </div>
    </x-slot>

    <div class="overflow-x-auto">
        <table class="w-full text-start text-sm divide-y divide-gray-200 dark:divide-white/10">
            @if (! empty($columns))
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        @foreach ($columns as $columnKey => $columnLabel)
                            <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-900 dark:text-white">
                                {{ $columnLabel }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
            @endif
            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                @forelse ($rows as $row)
                    <tr class="hover:bg-gray-50/50 dark:hover:bg-white/5 transition">
                        @foreach ($columns as $columnKey => $columnLabel)
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-200">
                                {!! $row[$columnKey] ?? '—' !!}
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($columns) ?: 1 }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                            لا توجد بيانات متاحة
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament::section>
