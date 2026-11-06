@extends('layouts.app')
@section('title', 'Pay input history')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $m = fn ($v) => $v === null ? '—' : \App\Payroll\Statutory\Format::money((string) $v);
    $color = ['created' => 'green', 'replaced' => 'amber', 'removed' => 'red', 'reversed' => 'brand'];
    $label = ['created' => 'Entered', 'replaced' => 'Replaced', 'removed' => 'Removed', 'reversed' => 'Upload reversed'];
    $source = ['screen' => 'Screen', 'import' => 'Excel upload', 'restored' => 'Restored'];
@endphp
<x-page-header title="Pay input history" description="Every change to the inputs of a pay period: the old and the new amount, who and when.">
    <x-slot:actions>@if ($period)<x-button :href="route('pay-inputs.index', ['scheme' => $period->scheme_id, 'period' => $period->id])" variant="secondary">Back to inputs</x-button>@endif</x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card>@include('payroll.inputs._picker', ['action' => route('pay-inputs.history')])</x-card>

    @if ($period)
        <x-card :padding="false">
            <form method="GET" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <input type="hidden" name="scheme" value="{{ $period->scheme_id }}"><input type="hidden" name="period" value="{{ $period->id }}">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Name or employee no" class="{{ $field }} lg:col-span-2">
                <x-combobox name="component" :options="$components->map(fn ($c) => ['value' => $c->id, 'label' => $c->code.' · '.$c->name])->values()" :value="request('component')" empty="All components" />
                <div><x-button type="submit" variant="secondary">Filter</x-button></div>
            </form>
            <x-table>
                <x-slot:head>
                    <tr><th class="px-5 py-3 font-medium">When</th><th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Component</th><th class="px-5 py-3 font-medium">Change</th>
                        <th class="px-5 py-3 text-right font-medium">Old</th><th class="px-5 py-3 text-right font-medium">New</th><th class="px-5 py-3 font-medium">By</th></tr>
                </x-slot:head>
                @forelse ($entries as $h)
                    <tr>
                        <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ $h->created_at->format('d M Y H:i') }}</td>
                        <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $h->employee->display_name ?? $h->employee->full_name }}</span><p class="text-xs text-slate-500">{{ $h->employee->employee_no }}</p></td>
                        <td class="px-5 py-3 font-mono text-xs">{{ $h->component->code }}</td>
                        <td class="px-5 py-3"><x-badge :color="$color[$h->action] ?? 'slate'">{{ $label[$h->action] ?? $h->action }}</x-badge>
                            <span class="block text-xs text-slate-500">{{ $source[$h->source] ?? $h->source }}@if ($h->import_batch_id) · upload #{{ $h->import_batch_id }}@endif</span></td>
                        <td class="px-5 py-3 text-right font-mono">{{ $m($h->old_amount) }}</td>
                        <td class="px-5 py-3 text-right font-mono font-medium">{{ $m($h->new_amount) }}</td>
                        <td class="px-5 py-3 text-slate-500">{{ $h->created_by_name ?? 'System' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">Nothing has been entered for this pay period yet.</td></tr>
                @endforelse
            </x-table>
            @if ($entries && $entries->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $entries->links() }}</div>@endif
        </x-card>
    @endif
</div>
@endsection
