@extends('layouts.app')
@section('title', 'Salary adjustments')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $color = ['staged' => 'amber', 'applied' => 'green', 'rolled_back' => 'red', 'discarded' => 'slate'];
@endphp
<x-page-header title="Salary adjustments" description="Increments and other changes to the salary amounts of the employees of a scheme. Every adjustment is reviewed before it is applied and can be rolled back.">
    <x-slot:actions>
        @can('salary-adjustments.import')
            <x-button :href="route('salary-adjustments.import.index')" variant="secondary">Import from Excel</x-button>
        @endcan
        @can('salary-adjustments.manage')
            <x-button :href="route('salary-adjustments.create')"><x-icon name="plus" class="size-4" /> New adjustment</x-button>
        @endcan
    </x-slot:actions>
</x-page-header>

<x-card :padding="false">
    <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
        <div class="w-64"><x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="request('scheme')" empty="All schemes" /></div>
        <select name="status" class="{{ $field }} w-56">
            <option value="">Any status</option>
            @foreach (\App\Models\SalaryAdjustmentBatch::STATUSES as $k => $t)<option value="{{ $k }}" @selected($status === $k)>{{ $t }}</option>@endforeach
        </select>
        <x-button type="submit" variant="secondary">Filter</x-button>
    </form>
    <x-table>
        <x-slot:head>
            <tr><th class="px-5 py-3 font-medium">#</th><th class="px-5 py-3 font-medium">Scheme</th><th class="px-5 py-3 font-medium">Effective from</th><th class="px-5 py-3 font-medium">Reason</th>
                <th class="px-5 py-3 font-medium">By</th><th class="px-5 py-3 text-right font-medium">Rows</th><th class="px-5 py-3 text-right font-medium">Employees changed</th>
                <th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th></tr>
        </x-slot:head>
        @forelse ($batches as $b)
            <tr>
                <td class="px-5 py-3 font-medium text-slate-900">#{{ $b->id }}</td>
                <td class="px-5 py-3">{{ $b->scheme->name }}</td>
                <td class="whitespace-nowrap px-5 py-3">{{ $b->effective_from->format('d M Y') }}</td>
                <td class="max-w-xs px-5 py-3 text-slate-600">{{ $b->reason }}<p class="text-xs text-slate-500">{{ $b->source === 'import' ? 'Excel: '.$b->file_name : 'Entered on screen' }}</p></td>
                <td class="px-5 py-3 text-slate-500">{{ $b->created_by_name ?? 'System' }}<span class="block text-xs">{{ $b->created_at->format('d M Y H:i') }}</span></td>
                <td class="px-5 py-3 text-right">{{ $b->total_rows }}@if ($b->error_rows)<span class="block text-xs text-red-600">{{ $b->error_rows }} errors</span>@endif</td>
                <td class="px-5 py-3 text-right">{{ $b->employees_changed }}</td>
                <td class="px-5 py-3"><x-badge :color="$color[$b->status] ?? 'slate'">{{ \App\Models\SalaryAdjustmentBatch::STATUSES[$b->status] ?? $b->status }}</x-badge></td>
                <td class="px-5 py-3 text-right"><x-button :href="route('salary-adjustments.show', $b)" variant="ghost" size="sm">Open</x-button></td>
            </tr>
        @empty
            <tr><td colspan="9" class="px-5 py-10 text-center text-slate-500">No salary adjustments yet.</td></tr>
        @endforelse
    </x-table>
    @if ($batches->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $batches->links() }}</div>@endif
</x-card>
@endsection
