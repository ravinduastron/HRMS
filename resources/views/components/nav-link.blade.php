{{-- Sidebar link. Relies on the parent layout's Alpine `compact` flag (icon-only rail). --}}
@props(['href', 'icon' => null, 'active' => false, 'child' => false])
<a href="{{ $href }}" @if ($active) aria-current="page" @endif
    @if ($icon) :title="compact ? @js((string) $slot) : null" @endif
    @class([
        'group relative flex items-center gap-3 rounded-md text-sm transition focus-visible:outline-2 focus-visible:outline-brand-300',
        'px-3 py-2' => ! $child, 'py-1.5 pl-3 pr-2' => $child,
        'bg-white/10 font-medium text-white' => $active,
        'text-brand-100/75 hover:bg-white/5 hover:text-white' => ! $active,
    ])>
    @if ($active && ! $child)<span class="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-accent-400"></span>@endif
    @if ($icon)<x-icon :name="$icon" />@endif
    <span @if ($icon) x-show="!compact" @endif class="truncate">{{ $slot }}</span>
</a>
