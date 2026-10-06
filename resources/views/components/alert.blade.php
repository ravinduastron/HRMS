@props(['type' => 'info', 'title' => null, 'dismissible' => false])
@php
    $styles = [
        'info'    => ['bg-sky-50 text-sky-900 ring-sky-200', 'info', 'text-sky-500'],
        'success' => ['bg-emerald-50 text-emerald-900 ring-emerald-200', 'check', 'text-emerald-500'],
        'warning' => ['bg-amber-50 text-amber-900 ring-amber-200', 'warning', 'text-amber-500'],
        'error'   => ['bg-red-50 text-red-900 ring-red-200', 'warning', 'text-red-500'],
    ];
    [$box, $icon, $iconColor] = $styles[$type] ?? $styles['info'];
@endphp
<div x-data="{ open: true }" x-show="open" role="alert" {{ $attributes->class("flex gap-3 rounded-md p-3.5 text-sm ring-1 ring-inset $box") }}>
    <x-icon :name="$icon" class="mt-0.5 size-5 {{ $iconColor }}" />
    <div class="flex-1">
        @if ($title)<p class="font-medium">{{ $title }}</p>@endif
        <div @class(['opacity-90' => $title])>{{ $slot }}</div>
    </div>
    @if ($dismissible)
        <button type="button" @click="open = false" class="-m-1 h-fit rounded p-1 opacity-60 hover:opacity-100" aria-label="Dismiss"><x-icon name="close" class="size-4" /></button>
    @endif
</div>
