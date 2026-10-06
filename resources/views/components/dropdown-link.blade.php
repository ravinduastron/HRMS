@props(['icon' => null])
<a {{ $attributes->class('flex items-center gap-2.5 px-3.5 py-2 text-sm text-slate-700 hover:bg-slate-50') }}>
    @if ($icon)<x-icon :name="$icon" class="size-4 text-slate-400" />@endif
    {{ $slot }}
</a>
