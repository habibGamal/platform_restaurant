<x-filament::section :heading="$heading">
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
