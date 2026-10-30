@extends('layouts.app')
@section('title', 'New loan')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $typeData = $types->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'method' => \App\Payroll\Loans\LoanSchedule::METHODS[$t->interest_method] ?? $t->interest_method,
        'rate' => \App\Payroll\Decimal::plain((string) $t->default_rate), 'max_term' => $t->max_term_months, 'max_amount' => $t->max_amount !== null ? \App\Payroll\Decimal::plain((string) $t->max_amount) : null])->values();
@endphp
<x-page-header title="New loan" description="The repayment schedule is calculated when you save. Existing loans are loaded from Excel instead.">
    <x-slot:actions><x-button :href="route('loans.index')" variant="secondary">Cancel</x-button></x-slot:actions>
</x-page-header>

@if ($types->isEmpty())
    <x-alert type="warning">There are no active loan types. Create one under Loans &amp; advances &gt; Loan types first.</x-alert>
@else
<form method="POST" action="{{ route('loans.store') }}" class="grid gap-6 lg:grid-cols-3"
    x-data="{
        types: @js($typeData), typeId: @js((string) old('loan_type_id', '')), amount: @js(old('amount', '')), rate: @js(old('rate', '')), term: @js(old('term', '')),
        res: null, busy: false,
        get t() { return this.types.find(x => String(x.id) === String(this.typeId)) ?? null; },
        async preview() {
            this.busy = true; this.res = null;
            try {
                const r = await fetch(@js(route('loans.preview')), { method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ loan_type_id: this.typeId, amount: this.amount, rate: this.rate, term: this.term }) });
                const j = await r.json();
                this.res = r.status === 422 ? { error: Object.values(j.errors ?? {}).flat()[0] ?? 'Check the values.' } : j;
            } catch (e) { this.res = { error: 'Could not calculate.' }; }
            this.busy = false;
        } }">
    @csrf
    <div class="space-y-6 lg:col-span-2">
        <x-card title="Loan">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-input name="employee_no" label="Employee number" :value="old('employee_no', $employee?->employee_no)" required autocomplete="off"
                    :hint="$employee ? ($employee->display_name ?? $employee->full_name) : 'As in the employee list.'" />
                <x-combobox name="loan_type_id" label="Loan type" x-model="typeId" placeholder="Choose a type" :options="$types->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->values()" />
                <x-input name="amount" label="Loan amount (Rs)" x-model="amount" :value="old('amount')" inputmode="decimal" required />
                <x-input name="rate" label="Interest rate (% a year)" x-model="rate" :value="old('rate')" inputmode="decimal"
                    hint="Leave empty to use the loan type's rate. 0 = no interest." />
                <x-input name="term" type="number" min="1" max="600" label="Term (months)" x-model="term" :value="old('term')" required />
                <x-input name="disbursed_on" type="date" label="Paid out on" :value="old('disbursed_on', today()->toDateString())" required />
                <x-input name="first_due_month" type="month" label="First deduction month" :value="old('first_due_month', today()->addMonthNoOverflow()->format('Y-m'))" required />
                <x-input name="reference" label="Reference" :value="old('reference')" hint="Optional. Leave empty to get LN-000123." />
                <div class="sm:col-span-2"><x-input name="notes" label="Notes" :value="old('notes')" /></div>
            </div>
            <p x-show="t" x-cloak class="mt-4 text-sm text-slate-500">
                <span x-text="t && t.name"></span>: <span x-text="t && t.method"></span>, default rate <span x-text="t && t.rate"></span>%<template x-if="t && t.max_term">, up to <span x-text="t.max_term"></span> months</template><template x-if="t && t.max_amount">, up to Rs <span x-text="t.max_amount"></span></template>.
            </p>
        </x-card>
        <div class="flex justify-end gap-2">
            <x-button :href="route('loans.index')" variant="secondary">Cancel</x-button>
            <x-button type="submit">Create loan</x-button>
        </div>
    </div>

    <x-card title="Schedule preview">
        <p class="text-sm text-slate-500">Nothing is saved until you press Create loan.</p>
        <x-button class="mt-4 w-full" variant="secondary" @click="preview()" x-bind:disabled="busy">Calculate installments</x-button>
        <div x-show="res" x-cloak class="mt-4">
            <p x-show="res && res.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="res && res.error"></p>
            <dl x-show="res && !res.error" class="space-y-3 text-sm">
                <div><dt class="text-slate-500">First installment</dt><dd class="font-mono text-slate-900" x-text="res && res.first ? res.first.total : ''"></dd>
                    <dd class="text-xs text-slate-500" x-text="res && res.first ? 'capital ' + res.first.capital + ' + interest ' + res.first.interest : ''"></dd></div>
                <div><dt class="text-slate-500">Last installment</dt><dd class="font-mono text-slate-900" x-text="res && res.last ? res.last.total : ''"></dd></div>
                <div><dt class="text-slate-500">Total interest</dt><dd class="font-mono text-slate-900" x-text="res ? res.interest : ''"></dd></div>
                <div><dt class="text-slate-500">Total to be repaid</dt><dd class="font-mono font-semibold text-slate-900" x-text="res ? res.repayable : ''"></dd></div>
            </dl>
        </div>
    </x-card>
</form>
@endif
@endsection
