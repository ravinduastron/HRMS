@php
    $max = config('bank.max_accounts_per_employee');
    $canAdd = auth()->user()->can('bank-accounts.add');
    $branchMap = $banks->mapWithKeys(fn ($b) => [$b->id => $b->branches->map(fn ($br) => ['id' => $br->id, 'code' => $br->code, 'name' => $br->name])->values()]);
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
@endphp
<div x-show="tab === 'bank'" x-cloak class="space-y-6">
    @if ($bankAccounts->isNotEmpty() && ! $bankAccounts->contains('is_active', true))
        <x-alert type="warning" title="No active bank account">This employee cannot be paid by bank transfer until one account is activated.</x-alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($bankAccounts as $a)
            <x-card :padding="false">
                <div class="space-y-3 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-slate-900">{{ $a->bank->name }}</p>
                            <p class="text-sm text-slate-500">{{ $a->branch->name }} · {{ $a->bank->code }}-{{ $a->branch->code }}</p>
                        </div>
                        <div class="flex gap-1">
                            <x-badge :color="$a->is_active ? 'green' : 'slate'">{{ $a->is_active ? 'Active' : 'Inactive' }}</x-badge>
                            @if ($a->approval_status !== 'approved')<x-badge color="amber">{{ ucfirst($a->approval_status) }}</x-badge>@endif
                        </div>
                    </div>

                    <div x-data="{ number: null, loading: false, timer: null,
                            async reveal() {
                                this.loading = true;
                                try {
                                    const r = await fetch(@js(route('employees.bank-accounts.reveal', [$employee, $a])), { headers: { Accept: 'application/json' } });
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
                            @can('bank-accounts.reveal')
                                <button type="button" x-show="!number" @click="reveal()" :disabled="loading" class="font-sans text-xs font-medium text-brand-700 hover:underline">Show</button>
                                <button type="button" x-show="number" x-cloak @click="hide()" class="font-sans text-xs font-medium text-brand-700 hover:underline">Hide</button>
                            @endcan
                        </p>
                    </div>

                    <dl class="text-sm"><dt class="text-xs text-slate-500">Account holder</dt><dd class="mt-0.5 text-slate-900">{{ $a->account_holder_name }}</dd></dl>
                    <p class="text-xs text-slate-500">Added {{ $a->created_at->format('d M Y') }}@if ($a->added_by_name) by {{ $a->added_by_name }}@endif</p>
                </div>

                @canany(['bank-accounts.activate', 'bank-accounts.remove'])
                    <div class="flex justify-end gap-1 border-t border-slate-100 bg-slate-50 px-5 py-2.5">
                        @can('bank-accounts.activate')
                            @unless ($a->is_active)
                                <x-confirm-action :action="route('employees.bank-accounts.activate', [$employee, $a])" method="PATCH" title="Make this the active account?" confirm="Set as active" variant="primary">
                                    <x-slot:trigger><x-button variant="ghost" size="sm">Set as active</x-button></x-slot:trigger>
                                    Salary will be paid to this account. The currently active account will become inactive.
                                </x-confirm-action>
                            @endunless
                        @endcan
                        @can('bank-accounts.remove')
                            <x-confirm-action :action="route('employees.bank-accounts.destroy', [$employee, $a])" method="DELETE" title="Remove this bank account?" confirm="Remove">
                                <x-slot:trigger><x-button variant="ghost" size="sm"><span class="text-red-600">Remove</span></x-button></x-slot:trigger>
                                Accounts cannot be edited, so to correct a mistake remove it and add a new one. The record is kept for audit.
                                @if ($a->is_active)<strong class="mt-2 block text-amber-700">This is the active account: the employee will have no active account afterwards.</strong>@endif
                                <input type="text" name="reason" maxlength="255" placeholder="Reason (optional)" class="{{ $field }} mt-3">
                            </x-confirm-action>
                        @endcan
                    </div>
                @endcanany
            </x-card>
        @empty
            <x-card class="lg:col-span-2"><p class="text-sm text-slate-500">No bank accounts on record.</p></x-card>
        @endforelse
    </div>

    @if ($canAdd)
        @if ($bankAccounts->count() >= $max)
            <x-alert type="info">This employee already has {{ $max }} accounts, the maximum. Remove one to add a different account.</x-alert>
        @elseif ($banks->isEmpty())
            <x-alert type="info">No banks have been set up yet. Add banks and branches under Administration → Banks &amp; branches.</x-alert>
        @else
            <x-card title="Add bank account">
                <form method="POST" action="{{ route('employees.bank-accounts.store', $employee) }}"
                    x-data="{ bank: @js(old('bank_id', '')), branch: @js(old('bank_branch_id', '')), branches: @js($branchMap) }" class="grid gap-5 sm:grid-cols-2">
                    @csrf
                    <div class="space-y-1.5">
                        <label for="bank_id" class="block text-sm font-medium text-slate-700">Bank</label>
                        <select id="bank_id" name="bank_id" x-model="bank" @change="branch = ''" required class="{{ $field }}">
                            <option value="">Select a bank</option>
                            @foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->name }} ({{ $b->code }})</option>@endforeach
                        </select>
                        @error('bank_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1.5">
                        <label for="bank_branch_id" class="block text-sm font-medium text-slate-700">Branch</label>
                        <select id="bank_branch_id" name="bank_branch_id" x-model="branch" :disabled="!bank" required class="{{ $field }} disabled:bg-slate-50">
                            <option value="">Select a branch</option>
                            <template x-for="b in (branches[bank] ?? [])" :key="b.id"><option :value="b.id" x-text="b.code + ' · ' + b.name"></option></template>
                        </select>
                        @error('bank_branch_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <x-input name="account_number" label="Account number" autocomplete="off" required />
                    <x-input name="account_number_confirmation" label="Confirm account number" autocomplete="off" required />
                    <div class="sm:col-span-2"><x-input name="account_holder_name" label="Account holder name" :value="old('account_holder_name', $employee->full_name)" required
                        hint="Accounts cannot be edited after saving. Please check the details." /></div>
                    <div class="flex justify-end sm:col-span-2"><x-button type="submit">Add account</x-button></div>
                </form>
            </x-card>
        @endif
    @endif
</div>
