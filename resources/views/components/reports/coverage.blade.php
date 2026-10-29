@props([
    'complete' => 0,
    'total' => 0,
])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200 font-medium']) }}>
    <x-reports.completeness-dot :status="$complete >= $total && $total > 0 ? 'complete' : ($complete > 0 ? 'closed' : 'missing')" />
    <span>{{ $complete }} من {{ $total }} فرع مكتمل البيانات</span>
</span>
