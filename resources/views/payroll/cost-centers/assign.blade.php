@extends('layouts.app')
@section('title', 'Assign cost centers')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $sep = config('costcenters.separator');
    $label = fn ($row) => $row ? \App\Payroll\CostCenterTree::label($nodes, (int) $row->cost_center_id, $sep) : null;
    $f = $filters;
@endphp
<x-page-header title="Assign cost centers" description="Filter the employees, choose a cost center and the date it starts. Employees get it as a new entry; the history is kept.">
    <x-slot:actions><x-button :href="route('cost-centers.index')" variant="secondary">Cost center tree</x-button></x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card :padding="false">
        <form method="GET" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
            <input type="search" name="q" value="{{ $f['q'] ?? '' }}" placeholder="Name or employee no" class="{{ $field }} lg:col-span-2">
            <x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="$f['scheme'] ?? ''" empty="All schemes" />
            <x-combobox name="department" :options="$departments->map(fn ($d) => ['value' => $d, 'label' => $d])->values()" :value="$f['department'] ?? ''" empty="All departments" />
            <x-combobox name="mihcm" :options="collect([['value' => '__empty__', 'label' => '(no MiHCM value)']])->concat($mihcmValues->map(fn ($v) => ['value' => $v, 'label' => $v]))->values()" :value="$f['mihcm'] ?? ''" empty="Any MiHCM cost center" />
            <select name="state" class="{{ $field }}">
                <option value="">Any assignment</option>
                <option value="none" @selected(($f['state'] ?? '') === 'none')>No cost center yet</option>
                <option value="assigned" @selected(($f['state'] ?? '') === 'assigned')>Has a cost center</option>
            </select>
            <select name="payroll" class="{{ $field }}">
                <option value="">Any payroll status</option>
                <option value="active" @selected(($f['payroll'] ?? '') === 'active')>In payroll</option>
            </select>
            <div><x-button type="submit" variant="secondary">Filter</x-button></div>
        </form>
    </x-card>

    <form method="POST" action="{{ route('cost-centers.assign.store') }}" x-data="{ all: false }"
        @submit="if (all && !confirm('Assign the cost center to ALL {{ $employees->total() }} employees matching the filters?')) $event.preventDefault()">
        @csrf
        @foreach ($f as $k => $v)@if ($v !== null && $v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif @endforeach

        <x-card title="Assign">
            <div class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-combobox name="cost_center_id" label="Cost center" :options="$options" placeholder="Choose a cost center" hint="Only the lowest level of each branch can be chosen." />
                <x-input name="effective_from" type="date" label="Effective from" :value="old('effective_from', today()->toDateString())" required />
                <div class="lg:col-span-2"><x-input name="change_note" label="Reason" :value="old('change_note')" required placeholder="e.g. Initial allocation" /></div>
            </div>
            @error('cost_center_id')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            <label class="mt-4 flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="all_matching" value="1" x-model="all" class="size-4 accent-brand-700">
                Apply to all {{ $employees->total() }} employees matching the filters, not only the ones ticked on this page
            </label>
            <div class="mt-4 flex justify-end"><x-button type="submit">Assign cost center</x-button></div>
        </x-card>

        <x-card :title="$employees->total().' employees'" :padding="false" class="mt-6">
            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="w-10 px-5 py-3"><input type="checkbox" class="size-4 accent-brand-700" aria-label="Select all on this page"
                            @change="document.querySelectorAll('.row-pick').forEach(c => c.checked = $event.target.checked)"></th>
                        <th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Department</th><th class="px-5 py-3 font-medium">Scheme</th>
                        <th class="px-5 py-3 font-medium">MiHCM cost center</th><th class="px-5 py-3 font-medium">Cost center today</th>
                    </tr>
                </x-slot:head>
                @forelse ($employees as $e)
                    @php $cur = $label($current[$e->id] ?? null); @endphp
                    <tr>
                        <td class="px-5 py-2.5"><input type="checkbox" name="employee_ids[]" value="{{ $e->id }}" class="row-pick size-4 accent-brand-700" aria-label="Select {{ $e->employee_no }}"></td>
                        <td class="px-5 py-2.5"><span class="font-medium text-slate-900">{{ $e->display_name ?? $e->full_name }}</span><p class="text-xs text-slate-500">{{ $e->employee_no }}</p></td>
                        <td class="px-5 py-2.5 text-sm text-slate-600">{{ $e->department ?? '—' }}</td>
                        <td class="px-5 py-2.5 text-sm text-slate-600">{{ $e->scheme?->name ?? '—' }}</td>
                        <td class="px-5 py-2.5 text-sm text-slate-600">{{ $e->mihcm_costcenter ?? '—' }}</td>
                        <td class="px-5 py-2.5 text-sm">@if ($cur){{ $cur }}@else<x-badge color="amber">None</x-badge>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No employees match.</td></tr>
                @endforelse
            </x-table>
            @if ($employees->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $employees->links() }}</div>@endif
        </x-card>
    </form>
</div>
@endsection
