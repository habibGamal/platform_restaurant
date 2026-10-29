@props([
    'status' => 'complete',
])

@php
    $config = match ($status) {
        'complete' => [
            'color' => 'bg-emerald-500',
            'tooltip' => 'مكتمل',
        ],
        'closed' => [
            'color' => 'bg-amber-500',
            'tooltip' => 'مغلق بدون مطابقة',
        ],
        'open' => [
            'color' => 'bg-sky-500',
            'tooltip' => 'مباشر',
        ],
        'missing' => [
            'color' => 'bg-rose-500',
            'tooltip' => 'بيانات ناقصة',
        ],
        'not_opened' => [
            'color' => 'bg-gray-400',
            'tooltip' => 'قبل الافتتاح',
        ],
        default => [
            'color' => 'bg-gray-300',
            'tooltip' => 'غير محدد',
        ],
    };
@endphp

<span
    title="{{ $config['tooltip'] }}"
    aria-label="{{ $config['tooltip'] }}"
    {{ $attributes->merge(['class' => 'inline-block w-2.5 h-2.5 rounded-full shrink-0 ' . $config['color']]) }}
></span>
