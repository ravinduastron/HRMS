@extends('layouts.app')
@section('title', 'Pay inputs')

@section('content')
@php
$field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
$m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
$open = $period?->isOpen();
$params = $period ? ['scheme' => $period->scheme_id, 'period' => $period->id] : [];
$source = ['screen' => 'Screen', 'import' => 'Excel upload', 'restored' => 'Restored'];
@endphp
<x-page-header title="Pay inputs" description="Amounts entered for a pay period: bonuses, incentives and variable allowances. A pay run reads them.">
    <x-slot:actions>
        @if ($period)
        <x-button :href="route('pay-inputs.history', $params)" variant="secondary">History</x-button>
        @can('pay-inputs.import')<x-button :href="route('pay-inputs.import.index', $params)" variant="secondary">Import from Excel</x-button>@endcan
            @if ($open)
                @can('pay-inputs.manage')<x-button :href="route('pay-inputs.entry', $params)"><x-icon name="plus" class="size-4" /> Enter on screen</x-button>@endcan
            @endif
        @endif
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card>@include('payroll.inputs._picker', ['action' => route('pay-inputs.index')])</x-card>

    @if ($period)
    @unless ($open)
    <x-alert type="info">This pay period is {{ strtolower(\App\Models\PayPeriod::STATUSES[$period->status]) }}, so its inputs cannot be changed.@can('pay-periods.reopen') @if ($period->status === 'closed')It can be reopened under Pay periods.@endif @endcan</x-alert>
    @endunless

    @if ($totals)
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($totals as $cid => $t)
        <x-stat-card :label="$codes[$cid] ?? 'Component'" :value="$m($t['sum'])" :delta="$t['count'].' '.\Illuminate\Support\Str::plural('employee', $t['count'])" />
        @endforeach
    </div>
    @endif

    <x-card :padding="false">
        <form method="GET" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
            <input type="hidden" name="scheme" value="{{ $period->scheme_id }}"><input type="hidden" name="period" value="{{ $period->id }}">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Name or employee no" class="{{ $field }} lg:col-span-2">
            <x-combobox name="component" :options="$components->map(fn ($c) => ['value' => $c->id, 'label' => $c->code.' · '.$c->name])->values()" :value="request('component')" empty="All components" />
            <div><x-button type="submit" variant="secondary">Filter</x-button></div>
        </form>
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Employee</th>
                    <th class="px-5 py-3 font-medium">Component</th>
                    <th class="px-5 py-3 text-right font-medium">Amount</th>
                    <th class="px-5 py-3 font-medium">Entered</th>
                    <th class="px-5 py-3 text-right font-medium">Actions</th>
                </tr>
            </x-slot:head>
            @forelse ($inputs as $i)
            <tr>
                <td class="px-5 py-3"><a href="{{ route('employees.show', $i->employee) }}" class="font-medium text-slate-900 hover:underline">{{ $i->employee->display_name ?? $i->employee->full_name }}</a>
                    <p class="text-xs text-slate-500">{{ $i->employee->employee_no }}</p>
                </td>
                <td class="px-5 py-3"><span class="font-mono text-xs font-medium text-slate-900">{{ $i->component->code }}</span>
                    <p class="text-xs text-slate-500">{{ $i->component->name }}</p>
                </td>
                <td class="px-5 py-3 text-right font-mono font-medium">{{ $m($i->amount) }}</td>
                <td class="px-5 py-3 text-slate-500">{{ $source[$i->source] ?? $i->source }}@if ($i->import_batch_id) <a href="{{ route('pay-inputs.import.show', $i->import_batch_id) }}" class="text-brand-700 hover:underline">#{{ $i->import_batch_id }}</a>@endif
                    <span class="block text-xs">{{ $i->entered_by_name ?? 'System' }} · {{ $i->updated_at->format('d M Y H:i') }}</span>
                </td>
                <td class="px-5 py-3 text-right">
                    @if ($open)
                    @can('pay-inputs.manage')
                    <x-confirm-action :action="route('pay-inputs.destroy', $i)" method="DELETE" title="Remove this pay input?" confirm="Remove">
                        <x-slot:trigger><x-button variant="ghost" size="sm"><span class="text-red-600">Remove</span></x-button></x-slot:trigger>
                        {{ $i->component->code }} for {{ $i->employee->display_name ?? $i->employee->full_name }} is removed from this period. The old value stays in the history.
                    </x-confirm-action>
                    @endcan
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="px-5 py-10 text-center text-slate-500">No inputs for this pay period yet.</td>
            </tr>
            @endforelse
        </x-table>
        @if ($inputs && $inputs->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $inputs->links() }}</div>@endif
    </x-card>
    @endif
</div>
@endsection