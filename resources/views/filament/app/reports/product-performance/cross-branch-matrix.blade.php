<x-filament-widgets::widget class="fi-wi-table">
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h3 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                        مصفوفة مقارنة مبيعات الأصناف بين الفروع
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        معدل بيع كل صنف يومياً لكل فرع مفتوح (يوضح الأصناف الأكثر طلباً حسب الفرع)
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <x-filament::button
                        size="xs"
                        :color="$metric === 'qty' ? 'primary' : 'gray'"
                        wire:click="setMetric('qty')"
                    >
                        الكمية / يوم
                    </x-filament::button>
                    <x-filament::button
                        size="xs"
                        :color="$metric === 'sales' ? 'primary' : 'gray'"
                        wire:click="setMetric('sales')"
                    >
                        المبيعات / يوم
                    </x-filament::button>
                </div>
            </div>
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-widgets::widget>
