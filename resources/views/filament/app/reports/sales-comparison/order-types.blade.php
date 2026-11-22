<x-filament-widgets::widget>
    <div class="space-y-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as $card)
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="text-sm text-gray-500">{{ $card['label'] }}</div>
                    <div class="mt-1 text-xl font-bold">{{ $card['sales'] }}</div>
                    <div class="mt-1 text-sm text-gray-500">{{ $card['orders'] }} طلب</div>
                </div>
            @endforeach
        </div>
        {{ $this->table }}
    </div>
</x-filament-widgets::widget>
