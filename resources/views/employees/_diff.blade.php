{{-- Renders {field: {old, new}} --}}
@php
    $show = fn ($v) => $v === null || $v === '' ? '—' : (is_bool($v) ? ($v ? 'Yes' : 'No') : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v));
@endphp
<ul class="space-y-1 text-sm">
    @foreach ($changes ?? [] as $field => $c)
        <li class="flex flex-wrap items-baseline gap-x-2">
            <span class="font-medium text-slate-700">{{ Str::headline($field) }}</span>
            @if (($c['old'] ?? null) === config('audit.mask'))
                <span class="italic text-slate-400">changed (value hidden)</span>
            @else
                <span class="break-all text-slate-500 line-through decoration-slate-300">{{ \Illuminate\Support\Str::limit((string) $show($c['old'] ?? null), 80) }}</span>
                <span class="text-slate-400">→</span>
                <span class="break-all text-slate-900">{{ \Illuminate\Support\Str::limit((string) $show($c['new'] ?? null), 80) }}</span>
            @endif
        </li>
    @endforeach
</ul>
