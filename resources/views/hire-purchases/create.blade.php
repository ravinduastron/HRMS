@extends('layouts.app')
@section('title', 'New hire purchase')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
@endphp
<x-page-header title="New hire purchase" description="Enter what the vendor gave: the installment amount and the number of installments. Installments are equal, one per month.">
    <x-slot:actions><x-button :href="route('hire-purchases.index')" variant="secondary">Cancel</x-button></x-slot:actions>
</x-page-header>

@if ($vendors->isEmpty())
    <x-alert type="warning">There are no active vendors. Add one under Hire purchases &gt; Vendors first.</x-alert>
@elseif ($components->isEmpty())
    <x-alert type="warning">There is no active deduction component. Run the HirePurchaseComponentSeeder, or create a deduction under Payroll &gt; Pay components.</x-alert>
@else
<form method="POST" action="{{ route('hire-purchases.store') }}" class="space-y-6"
    x-data="{
        scheme: @js($initialScheme), employee: @js((string) old('employee_id', $employee?->id ?? '')), employees: [], loading: false,
        vendor: @js((string) old('hire_purchase_vendor_id', '')), component: @js((string) old('pay_component_id', $defaultComponent)),
        amount: @js(old('amount', '')), term: @js(old('term', '')),
        get totalText() {
            const a = String(this.amount).replace(/[, ]/g, '');
            if (!/^\d{1,13}(\.\d{1,2})?$/.test(a) || !/^\d{1,3}$/.test(String(this.term)) || Number(this.term) < 1) return null;
            const p = a.split('.');
            const cents = BigInt(p[0] + (p[1] || '').padEnd(2, '0')) * BigInt(this.term);
            const s = cents.toString().padStart(3, '0');
            return s.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + s.slice(-2);
        },
        async loadEmployees(keep) {
            this.employees = [];
            if (!this.scheme) { this.employee = ''; return; }
            this.loading = true;
            try {
                const r = await fetch(@js(route('hire-purchases.employees')) + '?scheme=' + encodeURIComponent(this.scheme), { headers: { Accept: 'application/json' } });
                if (!r.ok) throw new Error();
                this.employees = await r.json();
            } catch (e) { window.toast('Could not load the employees.', 'error'); }
            this.loading = false;
            if (!keep || !this.employees.some(e => String(e.value) === String(this.employee))) this.employee = '';
        },
        pickScheme(v) { this.scheme = v; this.loadEmployees(false); } }"
    x-init="loadEmployees(true)">
    @csrf

    <x-card title="Who and from whom">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-combobox name="scheme" label="Scheme" x-model="scheme" @change="pickScheme($event.detail.value)" placeholder="Choose a scheme"
                :options="$schemeOptions" hint="Only the schemes allocated to you are listed." />
            <x-combobox name="employee_id" label="Employee" x-model="employee" placeholder="Choose an employee"
                options-js="employees" disabled-js="!scheme || loading" />
            <p x-show="scheme && !loading && employees.length === 0" x-cloak class="text-sm text-amber-700 sm:col-span-2">There are no active employees in this scheme.</p>
            <x-combobox name="hire_purchase_vendor_id" label="Vendor" x-model="vendor" placeholder="Choose a vendor"
                :options="$vendors->map(fn ($v) => ['value' => $v->id, 'label' => $v->name.' ('.$v->code.')'])->values()" />
            <x-combobox name="pay_component_id" label="Deducted as (pay component)" x-model="component" placeholder="Choose a deduction component" :options="$components"
                hint="Any active deduction. HIRE_PURCHASE is the default." />
        </div>
    </x-card>

    <x-card title="The purchase">
        <div class="grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2"><x-input name="item_description" label="What was bought" :value="old('item_description')" required placeholder="e.g. Refrigerator, 2 door" /></div>
            <x-input name="amount" label="Installment amount (Rs)" x-model="amount" :value="old('amount')" inputmode="decimal" required hint="The monthly amount the vendor gave." />
            <x-input name="term" type="number" min="1" max="120" label="Number of installments" x-model="term" :value="old('term')" required />
            <x-input name="first_due_month" type="month" label="First deduction month" :value="old('first_due_month', today()->addMonthNoOverflow()->format('Y-m'))" required />
            <x-input name="reference" label="Reference" :value="old('reference')" hint="Optional (for example the vendor's agreement number). Leave empty to get HP-000123." />
            <div class="sm:col-span-2"><x-input name="notes" label="Notes" :value="old('notes')" /></div>
        </div>
        <p x-show="totalText" x-cloak class="mt-4 text-sm text-slate-600">Total to be deducted: <span class="font-mono font-semibold text-slate-900" x-text="'Rs ' + totalText"></span></p>
    </x-card>

    <div class="flex justify-end gap-2">
        <x-button :href="route('hire-purchases.index')" variant="secondary">Cancel</x-button>
        <x-button type="submit">Create hire purchase</x-button>
    </div>
</form>
@endif
@endsection
