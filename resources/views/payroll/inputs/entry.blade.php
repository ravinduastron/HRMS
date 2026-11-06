@extends('layouts.app')
@section('title', 'Enter pay inputs')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $open = $period?->isOpen();
    $variable = $payComponent?->latestVersion()?->calc_method === 'variable';
@endphp
<x-page-header title="Enter pay inputs" description="Enter one component for the employees of a pay period. An amount replaces the value in force (the old one stays in the history). Leave a box empty to keep it as it is.">
    <x-slot:actions>@if ($period)<x-button :href="route('pay-inputs.index', ['scheme' => $period->scheme_id, 'period' => $period->id])" variant="secondary">Back to inputs</x-button>@endif</x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card>@include('payroll.inputs._picker', ['action' => route('pay-inputs.entry')])</x-card>

    @if ($period)
        @unless ($open)
            <x-alert type="info">This pay period is {{ strtolower(\App\Models\PayPeriod::STATUSES[$period->status]) }}, so inputs cannot be entered.</x-alert>
        @else
            <x-card>
                <form method="GET" action="{{ route('pay-inputs.entry') }}" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="scheme" value="{{ $period->scheme_id }}"><input type="hidden" name="period" value="{{ $period->id }}">
                    <div class="w-96 max-w-full"><x-combobox name="component" label="Component" placeholder="Choose a component" :value="$payComponent?->id"
                        :options="$components->map(fn ($c) => ['value' => $c->id, 'label' => $c->code.' · '.$c->name])->values()" /></div>
                    <x-button type="submit" variant="secondary">Choose</x-button>
                </form>
                @if ($components->isEmpty())
                    <x-alert type="warning" class="mt-4">No component can be entered per period yet. Create a pay component whose method is "Entered each period" or "Variable amount".</x-alert>
                @endif
            </x-card>

            @if ($payComponent)
                @if ($variable)<x-alert type="info">{{ $payComponent->code }} is a variable amount: a value entered here replaces the amount on the employee's salary for this pay period only.</x-alert>@endif
                @if ($hidden > 0)<x-alert type="info">{{ $hidden }} payroll employee(s) of this scheme are not listed because {{ $payComponent->code }} is not on their salary at the end of this period.</x-alert>@endif

                <form method="POST" action="{{ route('pay-inputs.entry.save') }}" x-data="{ q: '' }">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period->id }}"><input type="hidden" name="component" value="{{ $payComponent->id }}">
                    <x-card :title="$payComponent->code.' · '.$payComponent->name" :padding="false">
                        <x-slot:actions><input type="search" x-model="q" placeholder="Find an employee" class="{{ $field }} w-56"></x-slot:actions>
                        <x-table>
                            <x-slot:head>
                                <tr><th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Amount (Rs)</th></tr>
                            </x-slot:head>
                            @forelse ($employees as $e)
                                @php $cur = $current[$e->id] ?? null; @endphp
                                <tr data-s="{{ mb_strtolower($e->employee_no.' '.($e->display_name ?? $e->full_name)) }}" x-show="!q || $el.dataset.s.includes(q.toLowerCase())">
                                    <td class="px-5 py-2.5"><span class="font-medium text-slate-900">{{ $e->display_name ?? $e->full_name }}</span><p class="text-xs text-slate-500">{{ $e->employee_no }}</p></td>
                                    <td class="px-5 py-2.5"><input type="text" name="amounts[{{ $e->id }}]" inputmode="decimal" autocomplete="off" value="{{ $cur ? \App\Payroll\Decimal::round((string) $cur->amount, 2) : '' }}" class="{{ $field }} max-w-52 font-mono"></td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="px-5 py-10 text-center text-slate-500">No employee of this scheme has {{ $payComponent->code }} on their salary.</td></tr>
                            @endforelse
                        </x-table>
                    </x-card>
                    @if ($employees->isNotEmpty())
                        <div class="mt-4 flex justify-end"><x-button type="submit">Save amounts</x-button></div>
                    @endif
                </form>
            @endif
        @endunless
    @endif
</div>
@endsection
