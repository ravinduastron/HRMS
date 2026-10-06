@props(['title' => null, 'padding' => true])
<section {{ $attributes->class('rounded-lg border border-slate-200 bg-white shadow-xs') }}>
    @if ($title || isset($actions))
        <header class="flex items-center justify-between gap-4 border-b border-slate-200 px-5 py-3.5">
            <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
            @isset($actions){{ $actions }}@endisset
        </header>
    @endif
    <div @class(['p-5' => $padding])>{{ $slot }}</div>
</section>
