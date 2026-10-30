@extends('layouts.app')
@section('title', 'Valuation '.$valuation->as_at->format('d M Y'))

@section('content')
@php $m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v); @endphp
<x-page-header :title="'Fair value as at '.$valuation->as_at->format('d M Y')" :description="'Market rate '.\App\Payroll\Decimal::plain((string) $valuation->market_rate).'% · saved by '.($valuation->created_by_name ?? 'System').' on '.$valuation->created_at->format('d M Y H:i').($valuation->note ? ' · '.$valuation->note : '')">
    <x-slot:actions>
        <x-button :href="route('loans.fair-value.snapshot.export', $valuation)" variant="secondary">Export CSV</x-button>
        <x-button :href="route('loans.fair-value')" variant="secondary">Back</x-button>
    </x-slot:actions>
</x-page-header>

@if ($partial)<x-alert type="info" class="mb-4">You see {{ $lines->count() }} of the {{ $valuation->loan_count }} loans in this valuation: only those of your schemes.</x-alert>@endif

<x-card :padding="false">
    <x-table>
        <x-slot:head>
            <tr><th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Loan</th><th class="px-5 py-3 text-right font-medium">Rate</th><th class="px-5 py-3 text-right font-medium">Left</th>
                <th class="px-5 py-3 text-right font-medium">Outstanding principal</th><th class="px-5 py-3 text-right font-medium">To be collected</th><th class="px-5 py-3 text-right font-medium">Fair value</th><th class="px-5 py-3 text-right font-medium">Difference</th></tr>
        </x-slot:head>
        @foreach ($lines as $l)
            <tr>
                <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $l->employee_name }}</span><p class="text-xs text-slate-500">{{ $l->employee_no }}</p></td>
                <td class="px-5 py-3">{{ $l->loan_reference }}<p class="text-xs text-slate-500">{{ $l->loan_type_name }}</p></td>
                <td class="px-5 py-3 text-right font-mono">{{ \App\Payroll\Decimal::plain((string) $l->contractual_rate) }}%</td><td class="px-5 py-3 text-right">{{ $l->remaining_installments }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($l->outstanding_principal) }}</td><td class="px-5 py-3 text-right font-mono">{{ $m($l->nominal_remaining) }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($l->fair_value) }}</td>
                <td class="px-5 py-3 text-right font-mono font-medium">{{ $m(\App\Payroll\Decimal::sub((string) $l->fair_value, (string) $l->outstanding_principal)) }}</td>
            </tr>
        @endforeach
        <tr class="bg-slate-50 font-medium text-slate-900">
            <td class="px-5 py-3" colspan="4">Total</td>
            <td class="px-5 py-3 text-right font-mono">{{ $m($totals['outstanding']) }}</td><td class="px-5 py-3 text-right font-mono">{{ $m($totals['nominal']) }}</td>
            <td class="px-5 py-3 text-right font-mono">{{ $m($totals['fair_value']) }}</td><td class="px-5 py-3 text-right font-mono">{{ $m($totals['difference']) }}</td>
        </tr>
    </x-table>
</x-card>
@endsection
