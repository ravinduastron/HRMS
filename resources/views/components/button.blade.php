@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'loading' => null])
@php
    $variants = [
        'primary'   => 'bg-brand-700 text-white shadow-xs hover:bg-brand-800',
        'secondary' => 'bg-white text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50',
        'ghost'     => 'text-slate-600 hover:bg-slate-100',
        'danger'    => 'bg-red-600 text-white shadow-xs hover:bg-red-700',
    ];
    $sizes = ['sm' => 'px-2.5 py-1.5 text-xs', 'md' => 'px-3.5 py-2 text-sm', 'lg' => 'px-4 py-2.5 text-sm'];
    $classes = 'inline-flex items-center justify-center gap-2 rounded-md font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 disabled:pointer-events-none disabled:opacity-60 '
        .($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['md']);
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    {{-- Pass :loading="'loading'" (an Alpine variable name) to show a spinner and disable while true. --}}
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}
        @if ($loading) :disabled="{{ $loading }}" @endif>
        @if ($loading)
            <svg x-show="{{ $loading }}" x-cloak class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/>
                <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
            </svg>
        @endif
        {{ $slot }}
    </button>
@endif
