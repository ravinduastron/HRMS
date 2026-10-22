{{-- Optional href makes the whole card a link. --}}
@props(['label', 'value', 'delta' => null, 'trend' => 'up', 'icon' => null, 'href' => null])
@php $tag = $href ? 'a' : 'div'; @endphp
<{{ $tag }} {{ $attributes->merge(['href' => $href])->class(['block rounded-lg border border-slate-200 bg-white p-5 shadow-xs', 'transition hover:border-brand-300 hover:shadow-sm' => $href]) }}>
    <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">{{ $label }}</p>
        @if ($icon)<x-icon :name="$icon" class="size-5 text-slate-400" />@endif
    </div>
    <p class="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{{ $value }}</p>
    @if ($delta)<p @class(['mt-1 text-xs', 'text-emerald-600' => $trend === 'up', 'text-amber-700' => $trend !== 'up'])>{{ $delta }}</p>@endif
</{{ $tag }}>
