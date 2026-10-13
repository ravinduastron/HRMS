@extends('layouts.app')
@section('title', 'Employees')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $tab = fn ($v) => route('employees.index', array_filter(['view' => $v === 'all' ? null : $v, 'period' => $v === 'all' ? null : ($period === 'last' ? null : $period)]));
    $mihcmColor = ['Active' => 'green', 'Inactive' => 'amber', 'Removed' => 'red'];
    $departmentOptions = $departments->map(fn ($d) => ['value' => $d, 'label' => $d])->values();
    $schemeOptions = $schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values();
@endphp
<x-page-header title="Employees" :description="$latestRun ? 'Last MiHCM sync '.$latestRun->finished_at?->diffForHumans().(auth()->user()->can('schemes.all') ? ' · '.$latestRun->fetched_count.' records' : '') : 'No MiHCM sync has completed yet.'">
    <x-slot:actions>
        @if (auth()->user()->can('employees.sync-log') && auth()->user()->can('schemes.all'))<x-button :href="route('employees.sync.index')" variant="secondary">Sync history</x-button>@endif
    </x-slot:actions>
</x-page-header>

{{-- Views --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <nav class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm" aria-label="Employee views">
        @foreach (['all' => 'All employees', 'new' => 'Newly added', 'deactivated' => 'Newly deactivated'] as $v => $label)
            <a href="{{ $tab($v) }}" @class(['rounded-md px-3 py-1.5 font-medium', 'bg-white text-slate-900 shadow-xs' => $view === $v, 'text-slate-600 hover:text-slate-900' => $view !== $v])>
                {{ $label }} <span class="ml-1 text-xs {{ $v !== 'all' && $counts[$v] ? 'rounded-full bg-accent-500 px-1.5 text-white' : 'text-slate-400' }}">{{ $counts[$v] }}</span>
            </a>
        @endforeach
    </nav>
    @if ($view !== 'all')
        <div class="flex items-center gap-2 text-sm text-slate-600">
            <span>Detected in</span>
            @foreach (['last' => 'latest sync', '7' => 'last 7 days', '30' => 'last 30 days'] as $p => $label)
                <a href="{{ route('employees.index', array_filter(['view' => $view, 'period' => $p === 'last' ? null : $p])) }}"
                    @class(['rounded-md px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => $period === $p, 'hover:bg-slate-100' => $period !== $p])>{{ $label }}</a>
            @endforeach
        </div>
    @endif
</div>

<x-card :padding="false">
    <form method="GET" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-6">
        <input type="hidden" name="view" value="{{ $view === 'all' ? '' : $view }}"><input type="hidden" name="period" value="{{ $period === 'last' ? '' : $period }}">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, employee no, NIC, email" class="{{ $field }} lg:col-span-2">
        <x-combobox name="department" :options="$departmentOptions" :value="request('department')" empty="All departments" />
        <x-combobox name="scheme" :options="$schemeOptions" :value="request('scheme')" empty="All schemes" />
        <select name="status" class="{{ $field }}"><option value="">Any status</option>
            <option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select>
        <div class="flex gap-2">
            <select name="payroll" class="{{ $field }}"><option value="">Any payroll</option>
                <option value="active" @selected(request('payroll') === 'active')>In payroll</option><option value="inactive" @selected(request('payroll') === 'inactive')>Not in payroll</option></select>
            <x-button type="submit" variant="secondary">Filter</x-button>
        </div>
    </form>

    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">Employee</th>
                <th class="px-5 py-3 font-medium">Department</th>
                <th class="px-5 py-3 font-medium">Designation</th>
                <th class="px-5 py-3 font-medium">{{ $view === 'all' ? 'Joined' : ($view === 'new' ? 'Added' : 'Deactivated') }}</th>
                <th class="px-5 py-3 font-medium">MiHCM</th>
                <th class="px-5 py-3 font-medium">Here</th>
                <th class="px-5 py-3 text-right font-medium">Actions</th>
            </tr>
        </x-slot:head>
        @forelse ($employees as $e)
            <tr>
                <td class="px-5 py-3">
                    <div class="flex items-center gap-3">
                        <x-avatar :name="$e->full_name ?? $e->employee_no" />
                        <div class="min-w-0">
                            <a href="{{ route('employees.show', $e) }}" class="font-medium text-slate-900 hover:underline">{{ $e->display_name ?? $e->full_name }}</a>
                            <p class="text-xs text-slate-500">{{ $e->employee_no }}</p>
                        </div>
                    </div>
                </td>
                <td class="px-5 py-3">{{ $e->department ?? '—' }}<p class="text-xs text-slate-500">{{ $e->mihcm_section }}</p></td>
                <td class="px-5 py-3">{{ $e->dsg_name ?? '—' }}@if ($e->scheme)<p class="text-xs text-slate-500">{{ $e->scheme->name }}</p>@endif</td>
                <td class="whitespace-nowrap px-5 py-3 text-slate-500">
                    {{ $view === 'all' ? $e->date_joined?->format('d M Y') : \Illuminate\Support\Carbon::parse($e->event_at)->format('d M Y H:i') }}
                </td>
                <td class="px-5 py-3"><x-badge :color="$mihcmColor[$e->mihcm_status]">{{ $e->mihcm_status }}</x-badge></td>
                <td class="px-5 py-3">
                    <div class="flex flex-wrap gap-1">
                        <x-badge :color="$e->is_active ? 'green' : 'slate'">{{ $e->is_active ? 'Active' : 'Inactive' }}</x-badge>
                        @unless ($e->is_payroll_active)<x-badge color="amber">No payroll</x-badge>@endunless
                    </div>
                </td>
                <td class="px-5 py-3">
                    <div class="flex justify-end gap-1">
                        <x-button :href="route('employees.show', $e)" variant="ghost" size="sm">View</x-button>
                        @can('employees.status')
                            <x-confirm-action :action="route('employees.status', $e)" method="PATCH" :title="($e->is_active ? 'Deactivate ' : 'Activate ').($e->display_name ?? $e->full_name).'?'"
                                :confirm="$e->is_active ? 'Deactivate' : 'Activate'" :variant="$e->is_active ? 'danger' : 'primary'">
                                <x-slot:trigger><x-button variant="ghost" size="sm">{{ $e->is_active ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
                                <input type="hidden" name="field" value="is_active">
                                This only changes the status in this system. The next MiHCM sync will not undo it.
                            </x-confirm-action>
                        @endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">
                @if ($view === 'all') No employees found. @if (auth()->user()->can('employees.sync-log') && auth()->user()->can('schemes.all'))Run a sync from <a class="text-brand-700 underline" href="{{ route('employees.sync.index') }}">Sync history</a>.@else If you expect to see employees here, ask an administrator to allocate your schemes.@endif
                @else Nothing {{ $view === 'new' ? 'new' : 'deactivated' }} in this period. @endif
            </td></tr>
        @endforelse
    </x-table>
    @if ($employees->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $employees->links() }}</div>@endif
</x-card>
@endsection
