@extends('layouts.app')
@section('title', $employee->display_name ?? $employee->full_name)

@section('content')
@php
    $d = fn ($date) => $date && !$date->isSameDay('1901-01-01') ? $date->format('d M Y') : '';
    $fundLabel = $employee->provident_fund === 'msps' ? 'MSPS' : 'EPF';
    $otherFund = $employee->provident_fund === 'msps' ? 'epf' : 'msps';
    // Add future modules here: set 'ready' => true once the tab partial exists (e.g. employees/tabs/_bank.blade.php).
    $tabs = [
        'profile'    => ['label' => 'Profile', 'ready' => true],
        'employment' => ['label' => 'Employment', 'ready' => true],
        'bank'       => ['label' => 'Bank accounts', 'ready' => true, 'visible' => auth()->user()->can('bank-accounts.view')],
        'salary'     => ['label' => 'Salary', 'ready' => true, 'visible' => auth()->user()->can('employee-salary.view')],
        'history'    => ['label' => 'History', 'ready' => true],
    ];
    $initialTab = array_key_exists(request()->string('tab')->toString(), $tabs) ? request()->string('tab')->toString() : 'profile';
    if ($errors->hasAny(['bank_id', 'bank_branch_id', 'account_number', 'account_holder_name'])) {
        $initialTab = 'bank'; // a failed "add account" must reopen the Bank tab
    }
    $profile = [
        'Full name' => $employee->full_name, 'Display name' => $employee->display_name, 'Title' => $employee->emp_title,
        'Calling name' => $employee->calling_name, 'Surname' => $employee->surname, 'NIC' => $employee->nic_no,
        'Date of birth' => $d($employee->birthday), 'Gender' => $employee->gender, 'Marital status' => $employee->marital_status,
        'Mobile' => $employee->mobile, 'Email' => $employee->email, 'Permanent address' => $employee->full_address,
    ];
    $employment = [
        'Employee no' => $employee->employee_no, 'Provident fund' => $fundLabel, 'EPF / MSPS no' => $employee->fund_no, 'Employment type' => $employee->emp_type,
        'Job category' => $employee->ct_name, 'Designation' => $employee->dsg_name, 'Grade' => $employee->grade_name, 'Scheme' => $employee->scheme_name,
        'Department' => $employee->department, 'Section' => $employee->mihcm_section, 'Team' => $employee->team,
        'Date joined' => $d($employee->date_joined), 'Contract expiry' => $d($employee->contract_expiry_date),
        'Resigned' => $d($employee->resigned_date), 'Last working day' => $d($employee->last_working_date),
        'Reports to' => trim(($employee->reporting_person_name ?? '').' '.($employee->reporting_person_employee_no ? '('.$employee->reporting_person_employee_no.')' : '')),
    ];
@endphp
<x-page-header :title="$employee->display_name ?? $employee->full_name" :description="$employee->employee_no.' · '.($employee->dsg_name ?? 'No designation')">
    <x-slot:actions>
        @can('employees.status')
            @foreach (['is_active' => ['Employee', 'Activate', 'Deactivate'], 'is_payroll_active' => ['Payroll', 'Include in payroll', 'Exclude from payroll']] as $field => [$name, $on, $off])
                <x-confirm-action :action="route('employees.status', $employee)" method="PATCH" :title="($employee->$field ? $off : $on).'?'" :confirm="$employee->$field ? $off : $on"
                    :variant="$employee->$field ? 'danger' : 'primary'">
                    <x-slot:trigger><x-button :variant="$employee->$field ? 'secondary' : 'primary'">{{ $employee->$field ? $off : $on }}</x-button></x-slot:trigger>
                    <input type="hidden" name="field" value="{{ $field }}">
                    {{ $field === 'is_active' ? 'Changes the employee status in this system only.' : 'Controls whether this employee is included when payroll is processed.' }}
                    The MiHCM sync will not override this.
                </x-confirm-action>
            @endforeach
        @endcan

        @can('employees.fund')
            <x-confirm-action :action="route('employees.fund', $employee)" method="PATCH" :title="'Switch to '.strtoupper($otherFund).'?'" :confirm="'Switch to '.strtoupper($otherFund)" variant="primary">
                <x-slot:trigger><x-button variant="secondary">Switch to {{ strtoupper($otherFund) }}</x-button></x-slot:trigger>
                <input type="hidden" name="fund" value="{{ $otherFund }}">
                This employee is currently an {{ $fundLabel }} member. Payroll will use the {{ strtoupper($otherFund) }} base from the next calculation. MiHCM does not provide the fund type, so the sync will not change it.
            </x-confirm-action>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="mb-6 flex flex-wrap gap-2">
    <x-badge :color="$employee->is_active ? 'green' : 'slate'">{{ $employee->is_active ? 'Active here' : 'Inactive here' }}</x-badge>
    <x-badge :color="$employee->is_payroll_active ? 'brand' : 'amber'">{{ $employee->is_payroll_active ? 'In payroll' : 'Excluded from payroll' }}</x-badge>
    <x-badge :color="['Active' => 'green', 'Inactive' => 'amber', 'Removed' => 'red'][$employee->mihcm_status]">MiHCM: {{ $employee->mihcm_status }}</x-badge>
    <x-badge color="slate">Fund: {{ $fundLabel }}</x-badge>
    <span class="self-center text-xs text-slate-500">Last synced {{ $employee->last_synced_at?->diffForHumans() ?? 'never' }}</span>
</div>

<div x-data="{ tab: @js($initialTab) }"
    x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    <div class="mb-4 flex gap-1 border-b border-slate-200" role="tablist">
        @foreach ($tabs as $key => $t)
            @continue(! ($t['visible'] ?? true))
            @if ($t['ready'])
                <button type="button" role="tab" @click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'"
                    :class="tab === '{{ $key }}' ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    class="-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium">{{ $t['label'] }}</button>
            @else
                <span aria-disabled="true" title="Coming soon" class="-mb-px cursor-not-allowed whitespace-nowrap border-b-2 border-transparent px-4 py-2.5 text-sm text-slate-300">
                    {{ $t['label'] }} <span class="ml-1 rounded bg-slate-100 px-1 text-[10px] text-slate-400">soon</span></span>
            @endif
        @endforeach
    </div>

    @foreach (['profile' => $profile, 'employment' => $employment] as $key => $rows)
        <x-card x-show="tab === '{{ $key }}'" x-cloak>
            <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($rows as $label => $value)
                    <div><dt class="text-slate-500">{{ $label }}</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $value ?: '—' }}</dd></div>
                @endforeach
            </dl>
        </x-card>
    @endforeach

    @can('bank-accounts.view')@include('employees.tabs._bank')@endcan
    @can('employee-salary.view')@include('employees.tabs._salary')@endcan

    <div x-show="tab === 'history'" x-cloak class="space-y-6">
        <x-card title="MiHCM sync changes" :padding="false">
            @forelse ($changes as $c)
                <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-3 last:border-0">
                    <div>
                        <x-badge :color="['created' => 'green', 'imported' => 'slate', 'updated' => 'amber', 'deactivated' => 'red', 'reactivated' => 'brand'][$c->change_type] ?? 'slate'">{{ $c->change_type }}</x-badge>
                        <div class="mt-2">@include('employees._diff', ['changes' => $c->changes])</div>
                    </div>
                    @can('schemes.all')<a href="{{ route('employees.sync.show', $c->employee_sync_run_id) }}" class="shrink-0 text-xs text-brand-700 hover:underline">Sync #{{ $c->employee_sync_run_id }} · {{ $c->created_at->format('d M Y H:i') }}</a>@else<span class="shrink-0 text-xs text-slate-500">{{ $c->created_at->format('d M Y H:i') }}</span>@endcan
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-slate-500">No sync changes recorded.</p>
            @endforelse
        </x-card>
        @can('audit.view')<x-audit-timeline :model="$employee" />@endcan
    </div>
</div>
@endsection
