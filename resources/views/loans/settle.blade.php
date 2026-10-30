@extends('layouts.app')
@section('title', 'Settle loan '.$loan->reference)

@section('content')
@php
    $m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
    $canWaive = auth()->user()->can('loans.waive-interest');
@endphp
<x-page-header :title="'Settle loan '.$loan->reference" :description="($loan->employee->display_name ?? $loan->employee->full_name).' · '.$loan->type->name">
    <x-slot:actions><x-button :href="route('loans.show', $loan)" variant="secondary">Back to the loan</x-button></x-slot:actions>
</x-page-header>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-card title="Quote">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <x-input name="date" type="date" label="Settlement date" :value="$date->toDateString()" required />
                @if ($canWaive)
                    <label class="flex items-center gap-2 pb-2 text-sm text-slate-700"><input type="checkbox" name="ignore" value="1" class="size-4 accent-brand-700" @checked($ignore)> Ignore the interest</label>
                @endif
                <x-button type="submit" variant="secondary">Recalculate</x-button>
            </form>

            @if ($error)
                <x-alert type="error" class="mt-4">{{ $error }}</x-alert>
            @elseif ($quote)
                <dl class="mt-5 divide-y divide-slate-100 text-sm">
                    <div class="flex justify-between py-2.5"><dt class="text-slate-600">Outstanding principal</dt><dd class="font-mono">{{ $m($quote['principal']) }}</dd></div>
                    <div class="flex justify-between py-2.5"><dt class="text-slate-600">Interest by this loan's rule ({{ \App\Payroll\Loans\LoanSettlement::RULES[$quote['rule']] ?? $quote['rule'] }})</dt><dd class="font-mono">{{ $m($quote['interest_by_rule']) }}</dd></div>
                    <div class="flex justify-between py-2.5 text-xs text-slate-500"><dt>For comparison: all scheduled interest {{ $m($quote['interest_full']) }}; accrued to the date {{ $m($quote['interest_accrued']) }}</dt><dd></dd></div>
                    @if ($quote['ignored'])
                        <div class="flex justify-between py-2.5"><dt class="text-amber-700">Interest ignored</dt><dd class="font-mono text-amber-700">− {{ $m($quote['waived']) }}</dd></div>
                    @endif
                    <div class="flex justify-between py-2.5"><dt class="text-slate-600">Interest to collect</dt><dd class="font-mono">{{ $m($quote['interest']) }}</dd></div>
                    <div class="flex justify-between py-3 text-base"><dt class="font-semibold text-slate-900">To be received in cash</dt><dd class="font-mono font-semibold text-slate-900">{{ $m($quote['total']) }}</dd></div>
                </dl>
            @endif
        </x-card>
    </div>

    @if ($quote)
        <x-card title="Record the settlement">
            <form method="POST" action="{{ route('loans.settle.store', $loan) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="date" value="{{ $date->toDateString() }}">
                <input type="hidden" name="ignore" value="{{ $ignore ? 1 : 0 }}">
                <x-input name="received_on" type="date" label="Cash received on" :value="old('received_on', today()->toDateString())" required />
                <x-input name="receipt" label="Receipt number" :value="old('receipt')" hint="Optional." />
                <x-input name="reason" label="{{ $ignore ? 'Reason for ignoring the interest' : 'Note' }}" :value="old('reason')" :required="$ignore" />
                <x-button type="submit" class="w-full">Settle the loan</x-button>
                <p class="text-xs text-slate-500">The loan closes and its remaining installments are marked settled. The cash is recorded here, not deducted from pay.@if ($ignore) The ignored interest is kept in the loan's ledger and the audit log.@endif</p>
            </form>
        </x-card>
    @endif
</div>
@endsection
