@extends('layouts.app')
@section('title', 'Company')

@section('content')
@php
    $user = auth()->user();
    $canEdit = $user->can('company.manage') && $user->can('schemes.all');
    $canAccounts = $user->can('company-bank-accounts.manage') && $user->can('schemes.all');
    $canReveal = $user->can('company-bank-accounts.reveal');
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $labels = ['legal_name' => 'Legal name', 'trading_name' => 'Trading name', 'registration_no' => 'Company registration no', 'tin' => 'TIN',
        'phone' => 'Phone', 'email' => 'Email', 'epf_employer_no' => 'EPF employer registration no', 'etf_employer_no' => 'ETF employer registration no', 'address' => 'Address'];
    $branchMap = $banks->mapWithKeys(fn ($b) => [$b->id => $b->branches->map(fn ($br) => ['id' => $br->id, 'code' => $br->code, 'name' => $br->name])->values()]);
    $bankOptions = $banks->map(fn ($b) => ['value' => $b->id, 'label' => $b->name.' ('.$b->code.')'])->values();
    $activeCount = $accounts->where('is_active', true)->count();
@endphp
<x-page-header title="Company" description="The legal entity and the bank accounts salaries are paid from. Payslips, returns and bank files read these." />

<div class="space-y-6">
    <x-card title="Company details">
        @if ($canEdit)
            <form method="POST" action="{{ route('company.update') }}" class="grid gap-5 sm:grid-cols-2">
                @csrf @method('PUT')
                <x-input name="legal_name" label="Legal name" :value="old('legal_name', $profile->legal_name)" required />
                <x-input name="trading_name" label="Trading name" :value="old('trading_name', $profile->trading_name)" />
                <x-input name="registration_no" label="Company registration no" :value="old('registration_no', $profile->registration_no)" />
                <x-input name="tin" label="TIN" :value="old('tin', $profile->tin)" />
                <x-input name="phone" label="Phone" :value="old('phone', $profile->phone)" />
                <x-input name="email" type="email" label="Email" :value="old('email', $profile->email)" />
                <x-input name="epf_employer_no" label="EPF employer registration no" :value="old('epf_employer_no', $profile->epf_employer_no)" />
                <x-input name="etf_employer_no" label="ETF employer registration no" :value="old('etf_employer_no', $profile->etf_employer_no)" />
                <div class="sm:col-span-2"><x-input name="address" label="Address" :value="old('address', $profile->address)" /></div>
                <div class="flex justify-end sm:col-span-2"><x-button type="submit">Save details</x-button></div>
            </form>
        @else
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($labels as $k => $t)
                    <div><dt class="text-slate-500">{{ $t }}</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $profile->$k ?: '—' }}</dd></div>
                @endforeach
            </dl>
        @endif
        @unless ($profile->exists)
            <p class="mt-4 text-sm text-amber-700">The company details have not been saved yet. The name shown is a placeholder from the system settings.</p>
        @endunless
    </x-card>

    <div>
        <h2 class="mb-3 text-sm font-semibold text-slate-900">Paying bank accounts</h2>
        @if ($accounts->isNotEmpty() && $activeCount === 0)
            <x-alert type="warning" class="mb-4" title="No active paying account">A pay run cannot choose an account to pay from until one is active.</x-alert>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            @forelse ($accounts as $a)
                <x-card :padding="false">
                    <div class="space-y-3 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-slate-900">{{ $a->name }}</p>
                                <p class="text-sm text-slate-500">{{ $a->bank->name }} · {{ $a->branch->name }} · {{ $a->bank->code }}-{{ $a->branch->code }}</p>
                            </div>
                            <div class="flex gap-1">
                                @if ($a->is_default)<x-badge color="brand">Default</x-badge>@endif
                                <x-badge :color="$a->is_active ? 'green' : 'slate'">{{ $a->is_active ? 'Active' : 'Inactive' }}</x-badge>
                            </div>
                        </div>

                        <div x-data="{ number: null, loading: false, timer: null,
                                async reveal() {
                                    this.loading = true;
                                    try {
                                        const r = await fetch(@js(route('company.accounts.reveal', $a)), { headers: { Accept: 'application/json' } });
                                        if (!r.ok) throw new Error();
                                        this.number = (await r.json()).account_number;
                                        this.timer = setTimeout(() => this.number = null, {{ config('bank.reveal_seconds') * 1000 }});
                                    } catch (e) { window.toast('Could not load the account number.', 'error'); }
                                    this.loading = false;
                                },
                                hide() { this.number = null; clearTimeout(this.timer); } }">
                            <p class="text-xs text-slate-500">Account number</p>
                            <p class="mt-0.5 flex items-center gap-3 font-mono text-base text-slate-900">
                                <span x-show="!number">{{ $a->masked() }}</span>
                                <span x-show="number" x-cloak x-text="number"></span>
                                @if ($canReveal)
                                    <button type="button" x-show="!number" @click="reveal()" :disabled="loading" class="font-sans text-xs font-medium text-brand-700 hover:underline">Show</button>
                                    <button type="button" x-show="number" x-cloak @click="hide()" class="font-sans text-xs font-medium text-brand-700 hover:underline">Hide</button>
                                @endif
                            </p>
                        </div>

                        <dl class="text-sm"><dt class="text-xs text-slate-500">Account holder</dt><dd class="mt-0.5 text-slate-900">{{ $a->account_holder_name }}</dd></dl>
                        <p class="text-xs text-slate-500">Added {{ $a->created_at->format('d M Y') }}@if ($a->added_by_name) by {{ $a->added_by_name }}@endif</p>
                    </div>

                    @if ($canAccounts)
                        <div class="flex flex-wrap justify-end gap-1 border-t border-slate-100 bg-slate-50 px-5 py-2.5">
                            @if ($a->is_active && ! $a->is_default)
                                <x-confirm-action :action="route('company.accounts.default', $a)" method="PATCH" title="Make this the default account?" confirm="Make default" variant="primary">
                                    <x-slot:trigger><x-button variant="ghost" size="sm">Make default</x-button></x-slot:trigger>
                                    A pay run will preselect this account. The current default stays available as a normal account.
                                </x-confirm-action>
                            @endif
                            @unless ($a->is_default)
                                <x-confirm-action :action="route('company.accounts.toggle', $a)" method="PATCH" :title="($a->is_active ? 'Deactivate ' : 'Activate ').$a->name.'?'"
                                    :confirm="$a->is_active ? 'Deactivate' : 'Activate'" :variant="$a->is_active ? 'danger' : 'primary'">
                                    <x-slot:trigger><x-button variant="ghost" size="sm">{{ $a->is_active ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
                                    {{ $a->is_active ? 'It can no longer be chosen for payment. Past payments are not affected.' : 'It can be chosen for payment again.' }}
                                </x-confirm-action>
                            @endunless
                            <x-confirm-action :action="route('company.accounts.destroy', $a)" method="DELETE" title="Remove this paying account?" confirm="Remove">
                                <x-slot:trigger><x-button variant="ghost" size="sm"><span class="text-red-600">Remove</span></x-button></x-slot:trigger>
                                Accounts cannot be edited, so to correct a mistake remove it and add a new one. The record is kept for audit. An account a pay run has paid from cannot be removed: deactivate it instead.
                                <input type="text" name="reason" maxlength="255" placeholder="Reason (optional)" class="{{ $field }} mt-3">
                            </x-confirm-action>
                        </div>
                    @endif
                </x-card>
            @empty
                <x-card class="lg:col-span-2"><p class="text-sm text-slate-500">No paying accounts yet. The first one you add becomes the default.</p></x-card>
            @endforelse
        </div>
    </div>

    @if ($canAccounts)
        @if ($banks->isEmpty())
            <x-alert type="info">No banks have been set up yet. Add banks and branches under Administration → Banks &amp; branches first.</x-alert>
        @else
            <x-card title="Add a paying account">
                <form method="POST" action="{{ route('company.accounts.store') }}"
                    x-data="{ bank: @js((string) old('bank_id', '')), branch: @js((string) old('bank_branch_id', '')), branches: @js($branchMap) }" class="grid gap-5 sm:grid-cols-2">
                    @csrf
                    <div class="sm:col-span-2"><x-input name="name" label="Nickname" :value="old('name')" required placeholder="e.g. Salary account, BOC Main" hint="Shown when choosing the account in a pay run." /></div>
                    <x-combobox name="bank_id" label="Bank" x-model="bank" @change="branch = ''" :options="$bankOptions" placeholder="Select a bank" />
                    <x-combobox name="bank_branch_id" label="Branch" x-model="branch" placeholder="Select a branch"
                        options-js="(branches[bank] ?? []).map(b => ({ value: b.id, label: b.code + ' · ' + b.name }))" disabled-js="!bank" />
                    <x-input name="account_number" label="Account number" autocomplete="off" required />
                    <x-input name="account_number_confirmation" label="Confirm account number" autocomplete="off" required />
                    <div class="sm:col-span-2"><x-input name="account_holder_name" label="Account holder name" :value="old('account_holder_name', $profile->legal_name)" required
                        hint="Accounts cannot be edited after saving. Please check the details." /></div>
                    <div class="flex justify-end sm:col-span-2"><x-button type="submit">Add account</x-button></div>
                </form>
            </x-card>
        @endif
    @endif
</div>
@endsection
