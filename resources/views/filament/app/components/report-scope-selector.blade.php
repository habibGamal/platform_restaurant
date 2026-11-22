<div class="flex items-center gap-2 text-sm" dir="rtl">
    <label class="sr-only" for="report-restaurant">المطعم</label>
    <select id="report-restaurant" wire:model.live="restaurant" class="rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:text-white" aria-label="المطعم">
        <option value="">كل المطاعم</option>
        @foreach ($restaurants as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
    </select>
    <details class="relative">
        <summary class="cursor-pointer rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-600 dark:text-white">الفروع ({{ count($this->branches) ?: 'الكل' }})</summary>
        <div class="absolute left-0 z-50 mt-1 max-h-64 min-w-48 overflow-y-auto rounded-lg border border-gray-200 bg-white p-3 shadow-lg dark:border-gray-700 dark:bg-gray-900">
            <p class="mb-2 text-xs text-gray-500">اترك الاختيار فارغاً لعرض كل الفروع المتاحة</p>
            @forelse ($availableBranches as $id => $name)
                <label class="flex items-center gap-2 py-1 dark:text-white" wire:key="scope-branch-{{ $id }}">
                    <input type="checkbox" wire:model.live="branches" value="{{ $id }}">
                    <span>{{ $name }}</span>
                </label>
            @empty
                <p class="py-1 text-xs text-gray-500">لا توجد فروع متاحة للاختيار</p>
            @endforelse
        </div>
    </details>
</div>
