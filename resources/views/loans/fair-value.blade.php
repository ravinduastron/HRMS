@extends('layouts.app')
@section('title', 'Loan fair value')

@section('content')
@php
$field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
$m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
$all = auth()->user()->can('schemes.all');
@endphp
<x-page-header title="Loan fair value" description="What the loans are worth when discounted at the market rate, compared with the principal still owed.">
    <x-slot:actions><x-button :href="route('loans.index')" variant="secondary">All loans</x-button></x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card title="Calculate">
        <form method="GET" class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-input name="as_at" type="date" label="Valuation date" :value="$asAt->toDateString()" required />
            <x-input name="rate" label="Market rate (% a year)" :value="$rate" inputmode="decimal" required
                :hint="$marketNow ? 'In force that day: '.\App\Payroll\Decimal::plain((string) $marketNow->rate).'%' : 'No market rate is set for that date: enter one.'" />
            <x-combobox name="scheme" label="Scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="$scheme" empty="All schemes" />
            <input type="hidden" name="run" value="1">
            <div class="lg:pt-[1.625rem]"><x-button type="submit">Calculate</x-button></div>
        </form>
        <p class="mt-4 text-sm text-slate-500">Each installment still to be collected (capital and interest) is discounted by the whole months from the valuation month to its due month: installment ÷ (1 + rate ÷ 12) ^ months. Fair value minus the outstanding principal is the difference to report: it is negative for loans cheaper than the market, such as interest-free staff loans.</p>
        @if ($error)<x-alert type="error" class="mt-4">{{ $error }}</x-alert>@endif
    </x-card>

    @if ($result)
    <x-card title="Result as at {{ $asAt->format('d M Y') }} at {{ $rate }}%" :padding="false">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-button :href="route('loans.fair-value.export', $runUrlParams)" variant="secondary" size="sm">Export CSV</x-button>
                @if ($all && ! $scheme && count($result['lines']))
                <x-confirm-action :action="route('loans.fair-value.save')" title="Save this valuation?" confirm="Save valuation" variant="primary">
                    <x-slot:trigger><x-button size="sm">Save as a snapshot</x-button></x-slot:trigger>
                    <input type="hidden" name="as_at" value="{{ $asAt->toDateString() }}"><input type="hidden" name="rate" value="{{ $rate }}">
                    The figures are saved exactly as shown, so a month-end valuation stays as it was calculated.
                    <input type="text" name="note" maxlength="255" placeholder="Note (optional)" class="{{ $field }} mt-3">
                </x-confirm-action>
                @endif
            </div>
        </x-slot:actions>
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Employee</th>
                    <th class="px-5 py-3 font-medium">Loan</th>
                    <th class="px-5 py-3 text-right font-medium">Rate</th>
                    <th class="px-5 py-3 text-right font-medium">Left</th>
                    <th class="px-5 py-3 text-right font-medium">Outstanding principal</th>
                    <th class="px-5 py-3 text-right font-medium">To be collected</th>
                    <th class="px-5 py-3 text-right font-medium">Fair value</th>
                    <th class="px-5 py-3 text-right font-medium">Difference</th>
                </tr>
            </x-slot:head>
            @forelse ($result['lines'] as $l)
            <tr>
                <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $l['employee_name'] }}</span>
                    <p class="text-xs text-slate-500">{{ $l['employee_no'] }}</p>
                </td>
                <td class="px-5 py-3"><a href="{{ route('loans.show', $l['loan']) }}" class="hover:underline">{{ $l['reference'] }}</a>
                    <p class="text-xs text-slate-500">{{ $l['type'] }}</p>
                </td>
                <td class="px-5 py-3 text-right font-mono">{{ $l['rate'] }}%</td>
                <td class="px-5 py-3 text-right">{{ $l['remaining'] }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($l['outstanding']) }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($l['nominal']) }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($l['fair_value']) }}</td>
                <td class="px-5 py-3 text-right font-mono font-medium">{{ $m($l['difference']) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="px-5 py-10 text-center text-slate-500">There are no active loans.</td>
            </tr>
            @endforelse
            @if (count($result['lines']))
            <tr class="bg-slate-50 font-medium text-slate-900">
                <td class="px-5 py-3" colspan="4">Total</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($result['totals']['outstanding']) }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($result['totals']['nominal']) }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($result['totals']['fair_value']) }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($result['totals']['difference']) }}</td>
            </tr>
            @endif
        </x-table>
        @if ($all && $scheme)<p class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500">A snapshot is saved for all schemes together. Clear the scheme filter to save one.</p>@endif
    </x-card>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="Market rates" :padding="false">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-5 py-3 font-medium">Effective from</th>
                        <th class="px-5 py-3 text-right font-medium">Rate</th>
                        <th class="px-5 py-3 font-medium">Reason</th>
                    </tr>
                </x-slot:head>
                @forelse ($marketRates as $r)
                <tr>
                    <td class="px-5 py-3 font-medium text-slate-900">{{ $r->effective_from->format('d M Y') }}</td>
                    <td class="px-5 py-3 text-right font-mono">{{ \App\Payroll\Decimal::plain((string) $r->rate) }}%</td>
                    <td class="px-5 py-3 text-slate-500">{{ $r->change_note }}<span class="block text-xs">{{ $r->created_by_name }}</span></td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" class="px-5 py-8 text-center text-slate-500">No market rate yet.</td>
                </tr>
                @endforelse
            </x-table>
            @if ($all)
            <form method="POST" action="{{ route('loans.fair-value.market-rates') }}" class="grid gap-3 border-t border-slate-200 p-5 sm:grid-cols-3">
                @csrf
                <x-input name="effective_from" type="date" label="Effective from" :value="old('effective_from', today()->toDateString())" required />
                <x-input name="rate" label="Rate (% a year)" :value="old('rate')" inputmode="decimal" required />
                <x-input name="change_note" label="Reason / source" :value="old('change_note')" required />
                <div class="sm:col-span-3"><x-button type="submit" variant="secondary">Add market rate</x-button></div>
            </form>
            @endif
        </x-card>

        <x-card title="Saved valuations" :padding="false">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-5 py-3 font-medium">As at</th>
                        <th class="px-5 py-3 text-right font-medium">Rate</th>
                        <th class="px-5 py-3 text-right font-medium">Loans</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </x-slot:head>
                @forelse ($snapshots as $s)
                <tr>
                    <td class="px-5 py-3 font-medium text-slate-900">{{ $s->as_at->format('d M Y') }}<span class="block text-xs font-normal text-slate-500">{{ $s->created_by_name }} · {{ $s->created_at->format('d M Y H:i') }}@if ($s->note) · {{ $s->note }}@endif</span></td>
                    <td class="px-5 py-3 text-right font-mono">{{ \App\Payroll\Decimal::plain((string) $s->market_rate) }}%</td>
                    <td class="px-5 py-3 text-right">{{ $s->loan_count }}</td>
                    <td class="px-5 py-3 text-right"><x-button :href="route('loans.fair-value.snapshot', $s)" variant="ghost" size="sm">Open</x-button></td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-5 py-8 text-center text-slate-500">Nothing saved yet.</td>
                </tr>
                @endforelse
            </x-table>
        </x-card>
    </div>
</div>
@endsection