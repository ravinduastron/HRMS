@extends('layouts.app')
@section('title', $type->exists ? 'Edit '.$type->name : 'New loan type')

@section('content')
@php
    $creating = ! $type->exists;
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $plain = fn ($v) => $v === null ? '' : \App\Payroll\Decimal::plain((string) $v);
@endphp
<x-page-header :title="$creating ? 'New loan type' : 'Edit '.$type->name" description="Changes apply to loans created from now on. A loan keeps the method and settlement rule it was created with." />

<form method="POST" action="{{ $creating ? route('loan-types.store') : route('loan-types.update', $type) }}" class="space-y-6">
    @csrf
    @unless ($creating) @method('PUT') @endunless

    <x-card title="Loan type">
        <div class="grid gap-5 sm:grid-cols-2">
            @if ($creating)
                <x-input name="code" label="Code" :value="old('code')" required autocomplete="off" placeholder="e.g. STAFF" hint="Letters, digits and underscore. Used in the Excel import; it cannot be changed later." />
            @else
                <div class="space-y-1.5"><p class="block text-sm font-medium text-slate-700">Code</p><p class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-700">{{ $type->code }}</p></div>
            @endif
            <x-input name="name" label="Name" :value="old('name', $type->name)" required placeholder="e.g. Staff loan" />
            <div class="sm:col-span-2"><x-input name="description" label="Description" :value="old('description', $type->description)" /></div>
        </div>
    </x-card>

    <x-card title="Interest and limits">
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="space-y-1.5">
                <label for="interest_method" class="block text-sm font-medium text-slate-700">Interest method</label>
                <select id="interest_method" name="interest_method" class="{{ $field }}">
                    @foreach (\App\Payroll\Loans\LoanSchedule::METHODS as $k => $t)<option value="{{ $k }}" @selected(old('interest_method', $type->interest_method) === $k)>{{ $t }}</option>@endforeach
                </select>
                @error('interest_method')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <p class="text-xs text-slate-500">Straight line: flat interest on the amount lent, equal installments. Declining balance: equal capital, interest on the balance, falling installments. EMI: equal installments, interest on the balance.</p>
            </div>
            <x-input name="default_rate" label="Default interest rate (% a year)" :value="old('default_rate', $plain($type->default_rate))" inputmode="decimal" required hint="0 = no interest. Can be changed per loan." />
            <x-input name="max_term_months" type="number" min="1" max="600" label="Longest term (months)" :value="old('max_term_months', $type->max_term_months)" hint="Optional." />
            <x-input name="max_amount" label="Largest amount (Rs)" :value="old('max_amount', $plain($type->max_amount))" inputmode="decimal" hint="Optional." />
        </div>
    </x-card>

    <x-card title="Early settlement">
        <div class="space-y-1.5 sm:w-2/3">
            <label for="settlement_interest_rule" class="block text-sm font-medium text-slate-700">Interest charged when a loan is settled early</label>
            <select id="settlement_interest_rule" name="settlement_interest_rule" class="{{ $field }}">
                @foreach (\App\Payroll\Loans\LoanSettlement::RULES as $k => $t)<option value="{{ $k }}" @selected(old('settlement_interest_rule', $type->settlement_interest_rule) === $k)>{{ $t }}</option>@endforeach
            </select>
            @error('settlement_interest_rule')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <p class="text-xs text-slate-500">Whoever settles a loan can still choose "ignore the interest" (needs its own permission and a reason).</p>
        </div>
    </x-card>

    <x-card title="Payslip lines">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-combobox name="capital_component_id" label="Capital repayment is deducted as" :options="$components" :value="old('capital_component_id', $type->capital_component_id)" placeholder="Choose a deduction component" />
            <x-combobox name="interest_component_id" label="Interest is deducted as" :options="$components" :value="old('interest_component_id', $type->interest_component_id)" placeholder="Choose a deduction component" />
        </div>
        <p class="mt-3 text-sm text-slate-500">Capital and interest are different components, so the payslip and paysheet show them on separate lines.</p>
    </x-card>

    <div class="flex justify-end gap-2">
        <x-button :href="route('loan-types.index')" variant="secondary">Cancel</x-button>
        <x-button type="submit">{{ $creating ? 'Create loan type' : 'Save changes' }}</x-button>
    </div>
</form>
@endsection
