@props(['name', 'size' => 'size-8'])
@php $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->join(''); @endphp
<span {{ $attributes->class("$size inline-grid shrink-0 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800") }}>{{ $initials }}</span>
