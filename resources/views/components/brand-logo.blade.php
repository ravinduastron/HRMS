{{-- Shows the company logo from PAYROLL_LOGO (path inside /public); falls back to a default mark. --}}
@props(['size' => 'h-8', 'showName' => false])
@php
    $logo = config('payroll.logo');
    $hasLogo = $logo && file_exists(public_path($logo));
@endphp
<span {{ $attributes->class('inline-flex items-center gap-2.5') }}>
    @if ($hasLogo)
        <img src="{{ asset($logo) }}" alt="{{ config('payroll.company') }}" class="{{ $size }} w-auto max-w-48 object-contain">
    @else
        <span class="{{ $size }} aspect-square grid place-items-center rounded-lg bg-brand-700 text-white">
            <svg viewBox="0 0 24 24" fill="none" class="size-3/5" aria-hidden="true">
                <path d="M5 7h14M5 12h14M5 17h8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
            </svg>
        </span>
    @endif
    @if ($showName)
        <span class="text-[15px] font-semibold tracking-tight text-slate-900">{{ config('payroll.name') }}</span>
    @endif
</span>
