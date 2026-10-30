@extends('layouts.app')
@section('title', 'Loan types')

@section('content')
<x-page-header title="Loan types" description="Each type has an interest method, a default rate, an early-settlement rule and the payslip lines for capital and interest.">
    <x-slot:actions>
        <x-button :href="route('loans.index')" variant="secondary">All loans</x-button>
        @can('loan-types.manage')<x-button :href="route('loan-types.create')"><x-icon name="plus" class="size-4" /> New loan type</x-button>@endcan
    </x-slot:actions>
</x-page-header>

<x-card :padding="false">
    <x-table>
        <x-slot:head>
            <tr><th class="px-5 py-3 font-medium">Type</th><th class="px-5 py-3 font-medium">Interest</th><th class="px-5 py-3 font-medium">Early settlement</th>
                <th class="px-5 py-3 font-medium">Payslip lines</th><th class="px-5 py-3 font-medium">Loans</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th></tr>
        </x-slot:head>
        @forelse ($types as $t)
            <tr>
                <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $t->name }}</span><p class="font-mono text-xs text-slate-500">{{ $t->code }}</p></td>
                <td class="px-5 py-3">{{ \App\Payroll\Loans\LoanSchedule::METHODS[$t->interest_method] ?? $t->interest_method }}
                    <p class="text-xs text-slate-500">{{ \App\Payroll\Decimal::plain((string) $t->default_rate) }}% a year @if ($t->max_term_months) · up to {{ $t->max_term_months }} months @endif</p></td>
                <td class="px-5 py-3 text-sm">{{ \App\Payroll\Loans\LoanSettlement::RULES[$t->settlement_interest_rule] ?? $t->settlement_interest_rule }}</td>
                <td class="px-5 py-3 text-xs text-slate-600">Capital: {{ $t->capitalComponent?->code ?? '—' }}<br>Interest: {{ $t->interestComponent?->code ?? '—' }}</td>
                <td class="px-5 py-3">{{ $t->loans_count }}</td>
                <td class="px-5 py-3"><x-badge :color="$t->is_active ? 'green' : 'slate'">{{ $t->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                <td class="px-5 py-3 text-right">
                    @can('loan-types.manage')
                        <div class="flex justify-end gap-1">
                            <x-button :href="route('loan-types.edit', $t)" variant="ghost" size="sm">Edit</x-button>
                            <x-confirm-action :action="route('loan-types.toggle', $t)" method="PATCH" :title="($t->is_active ? 'Deactivate ' : 'Activate ').$t->name.'?'" :confirm="$t->is_active ? 'Deactivate' : 'Activate'" :variant="$t->is_active ? 'danger' : 'primary'">
                                <x-slot:trigger><x-button variant="ghost" size="sm">{{ $t->is_active ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
                                {{ $t->is_active ? 'No new loans can use it. Existing loans are not affected.' : 'New loans can use it again.' }}
                            </x-confirm-action>
                        </div>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">No loan types yet.</td></tr>
        @endforelse
    </x-table>
</x-card>
@endsection
