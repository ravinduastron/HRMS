{{-- <x-dropdown align="right" width="w-64"> <x-slot:trigger>...</x-slot:trigger> menu content </x-dropdown> --}}
@props(['align' => 'right', 'width' => 'w-56', 'label' => 'Open menu', 'triggerClass' => ''])
<div x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false" class="relative">
    <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="true" aria-label="{{ $label }}"
        class="{{ $triggerClass }} focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
        {{ $trigger }}
    </button>
    <div x-show="open" x-cloak x-transition.origin.top.duration.100ms @click="open = false"
        class="absolute z-50 mt-2 {{ $width }} {{ $align === 'left' ? 'left-0' : 'right-0' }} rounded-lg bg-white py-1 shadow-lg ring-1 ring-slate-900/10">
        {{ $slot }}
    </div>
</div>
