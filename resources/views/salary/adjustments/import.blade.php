@extends('layouts.app')
@section('title', 'Import salary adjustments')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $color = ['staged' => 'amber', 'applied' => 'green', 'rolled_back' => 'red', 'discarded' => 'slate'];
@endphp
<x-page-header title="Import salary adjustments from Excel" description="Download the sheet of a scheme, fill in Mode and Value on the rows to change, upload it, review the result and confirm.">
    <x-slot:actions>
        <x-button :href="route('salary-adjustments.index')" variant="secondary">All adjustments</x-button>
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card>
        <form method="GET" action="{{ route('salary-adjustments.import.index') }}" class="flex flex-wrap items-end gap-3">
            <div class="w-64"><x-combobox name="scheme" label="Scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="$scheme?->id" /></div>
            <x-input name="date" type="date" label="Effective from" :value="$date->toDateString()" required />
            <x-button type="submit" variant="secondary">Choose</x-button>
        </form>
    </x-card>

    @if (! $scheme)
        <x-alert type="warning">No schemes are allocated to you.</x-alert>
    @else
        <div class="grid gap-6 lg:grid-cols-2">
            <x-card title="1. Download the sheet">
                <form method="GET" action="{{ route('salary-adjustments.import.template') }}" data-no-loading class="space-y-3">
                    <input type="hidden" name="scheme" value="{{ $scheme->id }}"><input type="hidden" name="date" value="{{ $date->toDateString() }}">
                    <p class="text-sm text-slate-600">One long sheet for {{ $scheme->name }}: one row per employee and component, with the amount on {{ $date->format('d M Y') }}@cannot('employee-salary.reveal') (left blank: you do not have the permission to show salary amounts)@endcannot. Choose the components:</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($components as $c)
                            <label class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm"><input type="checkbox" name="components[]" value="{{ $c->id }}" checked class="size-4 accent-brand-700">
                                <span class="font-mono text-xs">{{ $c->code }}</span> <span class="text-slate-500">{{ $c->name }}</span></label>
                        @endforeach
                    </div>
                    <x-button type="submit" variant="secondary">Download the Excel sheet</x-button>
                    <p class="text-xs text-slate-500">Mode is chosen per row: Increment amount, Increment portion or New salary. Rows with Mode and Value empty are ignored.</p>
                </form>
            </x-card>
            <x-card title="2. Upload the filled sheet">
                <form method="POST" action="{{ route('salary-adjustments.import.upload') }}" enctype="multipart/form-data" class="space-y-3">
                    @csrf
                    <input type="hidden" name="scheme" value="{{ $scheme->id }}"><input type="hidden" name="effective_from" value="{{ $date->toDateString() }}">
                    <x-input name="reason" label="Reason" :value="old('reason')" required placeholder="e.g. Annual increment 2026" />
                    <p class="text-sm text-slate-600">Effective from <span class="font-medium text-slate-900">{{ $date->format('d M Y') }}</span> (change it above).</p>
                    <input type="file" name="file" accept=".xlsx" required class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-800">
                    @error('file')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    @error('rows')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    <x-button type="submit">Upload and check</x-button>
                    <p class="text-xs text-slate-500">The file is checked row by row first; nothing changes until you confirm. The sheet only works for {{ $scheme->name }}.</p>
                </form>
            </x-card>
        </div>
    @endif

    <x-card title="Recent uploads" :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">#</th><th class="px-5 py-3 font-medium">File</th><th class="px-5 py-3 font-medium">Scheme</th><th class="px-5 py-3 font-medium">Effective from</th>
                    <th class="px-5 py-3 text-right font-medium">Rows</th><th class="px-5 py-3 text-right font-medium">Errors</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th></tr>
            </x-slot:head>
            @forelse ($batches as $b)
                <tr>
                    <td class="px-5 py-3 font-medium text-slate-900">#{{ $b->id }}</td>
                    <td class="px-5 py-3">{{ $b->file_name }}<p class="text-xs text-slate-500">{{ $b->created_by_name }} · {{ $b->created_at->format('d M Y H:i') }}</p></td>
                    <td class="px-5 py-3">{{ $b->scheme->name }}</td>
                    <td class="whitespace-nowrap px-5 py-3">{{ $b->effective_from->format('d M Y') }}</td>
                    <td class="px-5 py-3 text-right">{{ $b->total_rows }}</td><td class="px-5 py-3 text-right">{{ $b->error_rows }}</td>
                    <td class="px-5 py-3"><x-badge :color="$color[$b->status] ?? 'slate'">{{ \App\Models\SalaryAdjustmentBatch::STATUSES[$b->status] ?? $b->status }}</x-badge></td>
                    <td class="px-5 py-3 text-right"><x-button :href="route('salary-adjustments.show', $b)" variant="ghost" size="sm">Open</x-button></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-5 py-10 text-center text-slate-500">No uploads yet.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
@endsection
