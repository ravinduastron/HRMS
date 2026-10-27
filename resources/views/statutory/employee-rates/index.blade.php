@extends('layouts.app')
@section('title', 'Employee rates')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $fund = ['epf' => 'EPF', 'msps' => 'MSPS'];
@endphp
<x-page-header title="Statutory" description="The employee contribution rate of each employee. It follows the default of their fund unless they have their own." />
@include('statutory._tabs')

@unless ($rates)
    <x-alert type="warning" class="mb-4">No contribution rates are in force yet, so the defaults below are blank. Add them under Contribution rates.</x-alert>
@endunless

<x-card :padding="false">
    <form method="GET" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, employee no" class="{{ $field }}">
        <x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="request('scheme')" empty="All schemes" />
        <select name="own" class="{{ $field }}">
            <option value="">All employees</option>
            <option value="1" @selected(request('own') === '1')>Only those with their own rate</option>
        </select>
        <div><x-button type="submit" variant="secondary">Filter</x-button></div>
    </form>

    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Scheme</th><th class="px-5 py-3 font-medium">Fund</th>
                <th class="px-5 py-3 text-right font-medium">Fund default</th><th class="px-5 py-3 text-right font-medium">Rate used</th><th class="px-5 py-3"></th>
            </tr>
        </x-slot:head>
        @forelse ($employees as $e)
            @php
                $row = $own[$e->id] ?? null;
                $default = $rates[$e->provident_fund.'_employee'] ?? null;
                $mine = $row?->employee_rate !== null ? \App\Payroll\Decimal::plain((string) $row->employee_rate) : null;
            @endphp
            <tr>
                <td class="px-5 py-3"><a href="{{ route('statutory.employee-rates.show', $e) }}" class="font-medium text-slate-900 hover:underline">{{ $e->display_name ?? $e->full_name }}</a>
                    <p class="text-xs text-slate-500">{{ $e->employee_no }}</p></td>
                <td class="px-5 py-3">{{ $e->scheme?->name ?? '—' }}</td>
                <td class="px-5 py-3">{{ $fund[$e->provident_fund] ?? strtoupper($e->provident_fund) }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $default !== null ? $default.'%' : '—' }}</td>
                <td class="px-5 py-3 text-right">
                    @if ($mine !== null)<span class="font-mono font-medium text-slate-900">{{ $mine }}%</span> <x-badge color="amber">Own rate</x-badge>
                    @else<span class="font-mono text-slate-600">{{ $default !== null ? $default.'%' : '—' }}</span>@endif
                </td>
                <td class="px-5 py-3 text-right"><x-button :href="route('statutory.employee-rates.show', $e)" variant="ghost" size="sm">{{ auth()->user()->can('statutory.employee-rates') ? 'Set rate' : 'View' }}</x-button></td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No employees found.</td></tr>
        @endforelse
    </x-table>
    @if ($employees->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $employees->links() }}</div>@endif
</x-card>
@endsection
