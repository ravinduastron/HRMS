@extends('layouts.app')
@section('title', 'Loan '.$loan->reference)

@section('content')
@php
    $m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
    $plain = fn ($v) => \App\Payroll\Decimal::plain((string) $v);
    $field = 'mt-1.5 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $color = ['active' => 'green', 'settled' => 'slate', 'cancelled' => 'red'];
    $inst = ['pending' => ['Pending', 'amber'], 'deducted' => ['Deducted', 'green'], 'settled' => ['Settled early', 'slate'], 'cancelled' => ['Cancelled', 'red'], 'superseded' => ['Replaced by a rate change', 'slate']];
    $next = $pending->first();
    $remainingInterest = $pending->reduce(fn ($c, $i) => \App\Payroll\Decimal::add($c, (string) $i->interest_amount), '0');
    $firstRate = $rateChanges->isNotEmpty() ? $plain($rateChanges->last()->previous_rate) : $plain($loan->current_rate);
    $active = $loan->status === 'active';
    $nextMonth = ($next?->due_month ?? today())->format('Y-m');
@endphp
<x-page-header :title="'Loan '.$loan->reference" :description="($loan->employee->display_name ?? $loan->employee->full_name).' · '.$loan->type->name">
    <x-slot:actions>
        <x-badge :color="$color[$loan->status] ?? 'slate'">{{ ucfirst($loan->status) }}</x-badge>
        <x-button :href="route('loans.index')" variant="secondary">All loans</x-button>
        @if ($active)
            @can('loans.settle')<x-button :href="route('loans.settle', $loan)">Settle early</x-button>@endcan
            @can('loans.manage')
                <x-confirm-action :action="route('loans.cancel', $loan)" title="Cancel this loan?" confirm="Cancel loan">
                    <x-slot:trigger><x-button variant="secondary"><span class="text-red-600">Cancel loan</span></x-button></x-slot:trigger>
                    Only a loan with no deduction yet can be cancelled (for example one entered by mistake). Otherwise settle it.
                    <input type="text" name="reason" maxlength="500" required placeholder="Reason" class="{{ $field }}">
                </x-confirm-action>
            @endcan
        @endif
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card title="Summary">
        <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-slate-500">Employee</dt><dd class="mt-0.5 font-medium text-slate-900"><a href="{{ route('employees.show', $loan->employee) }}" class="hover:underline">{{ $loan->employee->display_name ?? $loan->employee->full_name }}</a>
                <span class="block text-xs font-normal text-slate-500">{{ $loan->employee->employee_no }}@if ($loan->employee->scheme) · {{ $loan->employee->scheme->name }}@endif</span></dd></div>
            <div><dt class="text-slate-500">Interest</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $plain($loan->current_rate) }}% a year
                <span class="block text-xs font-normal text-slate-500">{{ \App\Payroll\Loans\LoanSchedule::METHODS[$loan->interest_method] ?? $loan->interest_method }}@if ($firstRate !== $plain($loan->current_rate)) · started at {{ $firstRate }}%@endif</span></dd></div>
            <div><dt class="text-slate-500">Amount lent</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $m($loan->principal) }}<span class="block text-xs font-normal text-slate-500">{{ $loan->term_months }} months · paid out {{ $loan->disbursed_on->format('d M Y') }}</span></dd></div>
            <div><dt class="text-slate-500">Outstanding principal</dt><dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ $m($outstanding) }}</dd></div>
            <div><dt class="text-slate-500">Installments left</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $pending->count() }}</dd></div>
            <div><dt class="text-slate-500">Next installment</dt><dd class="mt-0.5 font-medium text-slate-900">@if ($next){{ $m($next->total()) }}<span class="block text-xs font-normal text-slate-500">{{ $next->due_month->format('M Y') }}: capital {{ $m($next->principal_amount) }} + interest {{ $m($next->interest_amount) }}</span>@else — @endif</dd></div>
            <div><dt class="text-slate-500">Interest still to come</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $m($remainingInterest) }}</dd></div>
            <div><dt class="text-slate-500">Early settlement</dt><dd class="mt-0.5 font-medium text-slate-900">{{ \App\Payroll\Loans\LoanSettlement::RULES[$loan->settlement_interest_rule] ?? $loan->settlement_interest_rule }}</dd></div>
            @if ($loan->status !== 'active')
                <div class="sm:col-span-2"><dt class="text-slate-500">Closed</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $loan->settled_on?->format('d M Y') }} · {{ $loan->closed_reason }}</dd></div>
            @endif
            @if ($loan->notes)<div class="sm:col-span-2"><dt class="text-slate-500">Notes</dt><dd class="mt-0.5 text-slate-900">{{ $loan->notes }}</dd></div>@endif
        </dl>
    </x-card>

    @if ($active && $pending->isNotEmpty())
        @can('loans.manage')
        <x-card title="Change the interest rate">
            <form method="POST" action="{{ route('loans.rate.store', $loan) }}" class="space-y-4"
                x-data="{ month: @js(old('effective_month', $nextMonth)), rate: @js(old('new_rate', '')), res: null, busy: false,
                    async preview() {
                        this.busy = true; this.res = null;
                        try {
                            const r = await fetch(@js(route('loans.rate.preview', $loan)), { method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ effective_month: this.month, new_rate: this.rate }) });
                            const j = await r.json();
                            this.res = r.status === 422 ? { error: Object.values(j.errors ?? {}).flat()[0] ?? 'Check the values.' } : j;
                        } catch (e) { this.res = { error: 'Could not calculate.' }; }
                        this.busy = false;
                    } }">
                @csrf
                <p class="text-sm text-slate-500">The unpaid installments from the chosen month are re-calculated on the outstanding balance, with the same number of installments left. Earlier installments and deductions are not touched. The old and new rates are kept in the history below.</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-input name="effective_month" type="month" label="Applies from (month)" x-model="month" required />
                    <x-input name="new_rate" label="New rate (% a year)" x-model="rate" inputmode="decimal" required :hint="'Now '.$plain($loan->current_rate).'%'" />
                    <x-input name="change_note" label="Reason" :value="old('change_note')" required />
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <x-button variant="secondary" @click="preview()" x-bind:disabled="busy">Preview the effect</x-button>
                    <x-button type="submit">Apply the new rate</x-button>
                </div>
                <div x-show="res" x-cloak>
                    <p x-show="res && res.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="res && res.error"></p>
                    <table x-show="res && !res.error" class="min-w-full text-left text-sm">
                        <thead class="text-xs text-slate-500"><tr><th class="py-2 pr-4 font-medium">From the chosen month</th><th class="py-2 pr-4 text-right font-medium">Before</th><th class="py-2 text-right font-medium">After</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr><td class="py-2 pr-4 text-slate-600">Outstanding principal re-priced</td><td class="py-2 pr-4 text-right font-mono" x-text="res && res.outstanding"></td><td class="py-2 text-right font-mono" x-text="res && res.outstanding"></td></tr>
                            <tr><td class="py-2 pr-4 text-slate-600">Installments left</td><td class="py-2 pr-4 text-right font-mono" x-text="res && res.count"></td><td class="py-2 text-right font-mono" x-text="res && res.count"></td></tr>
                            <tr><td class="py-2 pr-4 text-slate-600">Next installment</td><td class="py-2 pr-4 text-right font-mono" x-text="res && res.old_installment"></td><td class="py-2 text-right font-mono font-medium" x-text="res && res.new_installment"></td></tr>
                            <tr><td class="py-2 pr-4 text-slate-600">Interest still to come</td><td class="py-2 pr-4 text-right font-mono" x-text="res && res.old_interest"></td><td class="py-2 text-right font-mono font-medium" x-text="res && res.new_interest"></td></tr>
                            <tr><td class="py-2 pr-4 text-slate-600">Still to be repaid in total</td><td class="py-2 pr-4 text-right font-mono" x-text="res && res.old_total"></td><td class="py-2 text-right font-mono font-medium" x-text="res && res.new_total"></td></tr>
                        </tbody>
                    </table>
                </div>
            </form>
        </x-card>
        @endcan
    @endif

    <x-card title="Interest rate history" :padding="false">
        @if ($rateChanges->isEmpty())
            <p class="px-5 py-4 text-sm text-slate-500">The rate has not been changed: {{ $plain($loan->current_rate) }}% since the loan started.</p>
        @else
            <x-table>
                <x-slot:head>
                    <tr><th class="px-5 py-3 font-medium">Applies from</th><th class="px-5 py-3 font-medium">Previous rate</th><th class="px-5 py-3 font-medium">New rate</th>
                        <th class="px-5 py-3 text-right font-medium">Installment before → after</th><th class="px-5 py-3 text-right font-medium">Interest to come before → after</th><th class="px-5 py-3 font-medium">Changed by</th></tr>
                </x-slot:head>
                @foreach ($rateChanges as $c)
                    <tr>
                        <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ $c->effective_month->format('M Y') }}<span class="block text-xs font-normal text-slate-500">{{ $c->remaining_installments }} installments re-priced</span></td>
                        <td class="px-5 py-3 font-mono">{{ $plain($c->previous_rate) }}%</td>
                        <td class="px-5 py-3 font-mono font-medium text-slate-900">{{ $plain($c->new_rate) }}%</td>
                        <td class="px-5 py-3 text-right font-mono text-xs">{{ $m($c->old_installment) }} → {{ $m($c->new_installment) }}</td>
                        <td class="px-5 py-3 text-right font-mono text-xs">{{ $m($c->old_remaining_interest) }} → {{ $m($c->new_remaining_interest) }}</td>
                        <td class="px-5 py-3 text-slate-500">{{ $c->created_by_name ?? 'System' }}<span class="block text-xs">{{ $c->created_at->format('d M Y H:i') }} · {{ $c->change_note }}</span></td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>

    <x-card title="Repayment schedule" :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">#</th><th class="px-5 py-3 font-medium">Due</th><th class="px-5 py-3 text-right font-medium">Capital</th>
                    <th class="px-5 py-3 text-right font-medium">Interest</th><th class="px-5 py-3 text-right font-medium">Installment</th><th class="px-5 py-3 font-medium">Status</th></tr>
            </x-slot:head>
            @forelse ($schedule as $i)
                <tr @class(['text-slate-400' => in_array($i->status, ['settled', 'cancelled'], true)])>
                    <td class="px-5 py-2.5">{{ $i->seq }}</td>
                    <td class="whitespace-nowrap px-5 py-2.5">{{ $i->due_month->format('M Y') }}</td>
                    <td class="px-5 py-2.5 text-right font-mono">{{ $m($i->principal_amount) }}</td>
                    <td class="px-5 py-2.5 text-right font-mono">{{ $m($i->interest_amount) }}</td>
                    <td class="px-5 py-2.5 text-right font-mono font-medium">{{ $m($i->total()) }}</td>
                    <td class="px-5 py-2.5"><x-badge :color="$inst[$i->status][1] ?? 'slate'">{{ $inst[$i->status][0] ?? $i->status }}</x-badge>@if ($i->deducted_on)<span class="ml-1 text-xs text-slate-500">{{ $i->deducted_on->format('d M Y') }}</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-8 text-center text-slate-500">No installments.</td></tr>
            @endforelse
        </x-table>
        @if ($earlier->isNotEmpty())
            <details class="border-t border-slate-200 px-5 py-3 text-sm">
                <summary class="cursor-pointer font-medium text-brand-700">Earlier versions of the schedule ({{ $earlier->count() }} installments replaced by rate changes)</summary>
                <table class="mt-3 min-w-full text-left text-xs text-slate-600">
                    <thead><tr><th class="py-1.5 pr-4 font-medium">Version</th><th class="py-1.5 pr-4 font-medium">#</th><th class="py-1.5 pr-4 font-medium">Due</th><th class="py-1.5 pr-4 text-right font-medium">Capital</th><th class="py-1.5 pr-4 text-right font-medium">Interest</th><th class="py-1.5 text-right font-medium">Installment</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($earlier as $i)
                            <tr><td class="py-1.5 pr-4">{{ $i->schedule_version }}</td><td class="py-1.5 pr-4">{{ $i->seq }}</td><td class="py-1.5 pr-4">{{ $i->due_month->format('M Y') }}</td>
                                <td class="py-1.5 pr-4 text-right font-mono">{{ $m($i->principal_amount) }}</td><td class="py-1.5 pr-4 text-right font-mono">{{ $m($i->interest_amount) }}</td><td class="py-1.5 text-right font-mono">{{ $m($i->total()) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif
    </x-card>

    @if ($atStart)
        <x-card title="Fair value when the loan was paid out">
            <dl class="grid gap-4 text-sm sm:grid-cols-4">
                <div><dt class="text-slate-500">Market rate that day</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $atStart['rate'] }}%</dd></div>
                <div><dt class="text-slate-500">Amount lent</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $m($atStart['principal']) }}</dd></div>
                <div><dt class="text-slate-500">Fair value</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $m($atStart['fair_value']) }}</dd></div>
                <div><dt class="text-slate-500">Fair value minus amount lent</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $m($atStart['difference']) }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-slate-500">The original schedule discounted at the market rate of the day. A negative difference is the benefit the employee received by borrowing below the market rate.</p>
        </x-card>
    @endif

    <x-card title="Ledger" :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">Date</th><th class="px-5 py-3 font-medium">Entry</th><th class="px-5 py-3 text-right font-medium">Principal</th><th class="px-5 py-3 text-right font-medium">Interest</th><th class="px-5 py-3 font-medium">Note</th></tr>
            </x-slot:head>
            @foreach ($ledger as $e)
                <tr>
                    <td class="whitespace-nowrap px-5 py-2.5">{{ $e->effective_date->format('d M Y') }}</td>
                    <td class="px-5 py-2.5">{{ \App\Models\LoanLedgerEntry::TYPES[$e->entry_type] ?? $e->entry_type }}</td>
                    <td class="px-5 py-2.5 text-right font-mono">{{ $m($e->principal) }}</td>
                    <td class="px-5 py-2.5 text-right font-mono">{{ $m($e->interest) }}</td>
                    <td class="px-5 py-2.5 text-xs text-slate-500">{{ $e->note }}<span class="block">{{ $e->created_by_name }}</span></td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection
