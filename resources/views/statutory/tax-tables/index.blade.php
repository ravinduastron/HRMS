@extends('layouts.app')
@section('title', 'Tax tables')

@section('content')
@php
    $canManage = auth()->user()->can('statutory.manage') && auth()->user()->can('schemes.all');
    $money = fn ($v) => \App\Payroll\Statutory\Format::money($v);
@endphp
<x-page-header title="Statutory" :description="'Tax tables and contribution rates, versioned by effective date. Today falls in '.$taxYear['label'].'.'" />
@include('statutory._tabs')

<div class="space-y-10">
    @foreach ($panels as $p)
        @php $t = $p['table']; $v = $p['version']; $lump = $t->kind === 'lump_sum'; @endphp
        <section class="grid gap-6 lg:grid-cols-3">
            <x-card :title="$t->name" :padding="false" class="lg:col-span-2">
                <x-slot:actions>
                    @if ($canManage)<x-button :href="route('statutory.tax-tables.versions.create', ['table' => $t->code])" variant="secondary" size="sm">New version</x-button>@endif
                </x-slot:actions>

                @if ($v)
                    <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-3 text-sm">
                        @foreach ($p['versions'] as $x)
                            <a href="{{ route('statutory.tax-tables', array_merge(request()->query(), [$p['key'] => $x->id])) }}" @class([
                                'rounded-md px-3 py-1.5 font-medium ring-1 ring-inset',
                                'bg-brand-50 text-brand-800 ring-brand-200' => $x->id === $v->id,
                                'text-slate-600 ring-slate-200 hover:bg-slate-50' => $x->id !== $v->id,
                            ])>{{ $x->label }}@if ($x->id === $p['inForceId']) · in force @endif</a>
                        @endforeach
                    </div>
                    <p class="border-b border-slate-100 px-5 py-3 text-sm text-slate-500">
                        {{ $t->description }}<br>
                        Effective from {{ $v->effective_from->format('d M Y') }} · {{ $v->change_note }} · {{ $v->created_by_name ?? 'System' }}
                        @if ($v->source_note)<br>Source: {{ $v->source_note }}@endif
                    </p>

                    @include('statutory.tax-tables._slabs', ['slabs' => $p['slabs'], 'lump' => $lump])

                    <div class="space-y-1 border-t border-slate-200 px-5 py-3 text-sm">
                        @if ($p['problems'])
                            @foreach ($p['problems'] as $m)<p class="text-red-700">{{ $m }}</p>@endforeach
                        @else
                            <p class="text-emerald-700">The bands are contiguous and the last one has no limit.</p>
                            <p @class(['text-emerald-700' => \App\Payroll\Decimal::cmp($p['maxGap'], config('statutory.continuity_tolerance')) <= 0, 'text-amber-700' => \App\Payroll\Decimal::cmp($p['maxGap'], config('statutory.continuity_tolerance')) > 0])>
                                The largest difference between neighbouring bands at their boundary is Rs {{ $money($p['maxGap']) }}.</p>
                        @endif
                        @if ($p['cross'] !== null)
                            @if ($p['cross'])
                                @foreach ($p['cross'] as $m)<p class="text-amber-700">{{ $m }}</p>@endforeach
                            @else
                                <p class="text-emerald-700">Cross-check: the lump-sum fixed amounts are 12 × the monthly ones.</p>
                            @endif
                        @endif
                        @if ($lump)
                            <p class="pt-2 text-slate-500">Tax = D × rate − (fixed amount + monthly tax already deducted + monthly tax still to be deducted + lump-sum tax deducted earlier). If the result is below zero, the tax is nil.</p>
                        @else
                            <p class="pt-2 text-slate-500">Tax = income × rate − the amount in the last column. The relief is already built into the table, so nothing is deducted first.</p>
                        @endif
                    </div>
                @else
                    <p class="px-5 py-8 text-sm text-slate-500">No version yet. @if ($canManage)Use "New version" to add one.@endif</p>
                @endif
            </x-card>

            @if ($lump)
                @include('statutory.tax-tables._try_lump')
            @else
                @include('statutory.tax-tables._try_monthly')
            @endif
        </section>
    @endforeach
</div>
@endsection
