{{-- Collapsible sidebar group. In compact mode, clicking expands the whole rail. --}}
@props(['label', 'icon', 'active' => false])
<div x-data="{ open: @js($active) }">
    <button type="button" :title="compact ? @js($label) : null"
        @click="compact ? (collapsed = false, open = true) : open = !open" :aria-expanded="open"
        @class([
            'flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-brand-300',
            'font-medium text-white' => $active,
            'text-brand-100/75 hover:bg-white/5 hover:text-white' => ! $active,
        ])>
        <x-icon :name="$icon" />
        <span x-show="!compact" class="flex-1 truncate text-left">{{ $label }}</span>
        <x-icon name="chevron-down" x-show="!compact" class="size-4 transition-transform" ::class="open && 'rotate-180'" />
    </button>
    <div x-show="open && !compact" x-cloak x-transition.opacity.duration.150ms class="ml-[1.4rem] mt-0.5 space-y-0.5 border-l border-white/10 pl-2">
        {{ $slot }}
    </div>
</div>
