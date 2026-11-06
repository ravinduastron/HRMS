@extends('layouts.app')
@section('title', 'Import pay inputs')

@section('content')
@php
    $color = ['staged' => 'amber', 'applied' => 'green', 'reversed' => 'red', 'discarded' => 'slate'];
    $open = $period?->isOpen();
@endphp
<x-page-header title="Import pay inputs from Excel" description="Download the sheet of a pay period, fill in the amounts, upload it, review the result and confirm.">
    <x-slot:actions><x-button :href="route('pay-inputs.index', $period ? ['scheme' => $period->scheme_id, 'period' => $period->id] : [])" variant="secondary">Pay inputs</x-button></x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card>@include('payroll.inputs._picker', ['action' => route('pay-inputs.import.index')])</x-card>

    @if ($period)
        @unless ($open)
            <x-alert type="info">This pay period is {{ strtolower(\App\Models\PayPeriod::STATUSES[$period->status]) }}, so nothing can be uploaded for it.</x-alert>
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                <x-card title="1. Download the sheet">
                    @if ($components->isEmpty())
                        <p class="text-sm text-slate-500">No component can be entered per period yet.</p>
                    @else
                        <form method="GET" action="{{ route('pay-inputs.import.template') }}" data-no-loading class="space-y-3">
                            <input type="hidden" name="period" value="{{ $period->id }}">
                            <p class="text-sm text-slate-600">One row per employee who has the component on their salary, one column per component. Values already entered are filled in. Choose the components:</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($components as $c)
                                    <label class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm"><input type="checkbox" name="components[]" value="{{ $c->id }}" checked class="size-4 accent-brand-700">
                                        <span class="font-mono text-xs">{{ $c->code }}</span> <span class="text-slate-500">{{ $c->name }}</span></label>
                                @endforeach
                            </div>
                            <x-button type="submit" variant="secondary">Download the Excel sheet</x-button>
                        </form>
                    @endif
                </x-card>
                <x-card title="2. Upload the filled sheet">
                    <form method="POST" action="{{ route('pay-inputs.import.upload') }}" enctype="multipart/form-data" class="space-y-3">
                        @csrf
                        <input type="hidden" name="period" value="{{ $period->id }}">
                        <input type="file" name="file" accept=".xlsx" required class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-800">
                        @error('file')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                        <x-button type="submit">Upload and check</x-button>
                        <p class="text-xs text-slate-500">An amount replaces the value in force (the old one stays in the history). An empty cell changes nothing. The file is checked row by row first; nothing changes until you confirm. The sheet only works for {{ $period->label() }}.</p>
                    </form>
                </x-card>
            </div>
        @endunless
    @endif

    <x-card title="Uploads" :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">#</th><th class="px-5 py-3 font-medium">File</th><th class="px-5 py-3 font-medium">Pay period</th><th class="px-5 py-3 font-medium">By</th>
                    <th class="px-5 py-3 text-right font-medium">Amounts</th><th class="px-5 py-3 text-right font-medium">Valid</th><th class="px-5 py-3 text-right font-medium">Errors</th>
                    <th class="px-5 py-3 text-right font-medium">Changed</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th></tr>
            </x-slot:head>
            @forelse ($batches as $b)
                <tr>
                    <td class="px-5 py-3 font-medium text-slate-900">#{{ $b->id }}</td>
                    <td class="px-5 py-3">{{ $b->file_name }}<p class="text-xs text-slate-500">{{ $b->created_at->format('d M Y H:i') }}</p></td>
                    <td class="px-5 py-3 text-slate-600">{{ $b->period?->label() }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $b->uploaded_by_name }}</td>
                    <td class="px-5 py-3 text-right">{{ $b->total_rows }}</td><td class="px-5 py-3 text-right">{{ $b->valid_rows }}</td><td class="px-5 py-3 text-right">{{ $b->error_rows }}</td><td class="px-5 py-3 text-right">{{ $b->applied_rows }}</td>
                    <td class="px-5 py-3"><x-badge :color="$color[$b->status] ?? 'slate'">{{ ucfirst($b->status) }}</x-badge></td>
                    <td class="px-5 py-3 text-right"><x-button :href="route('pay-inputs.import.show', $b)" variant="ghost" size="sm">Open</x-button></td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-5 py-10 text-center text-slate-500">No uploads yet.</td></tr>
            @endforelse
        </x-table>
        @if ($batches->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $batches->links() }}</div>@endif
    </x-card>
</div>
@endsection
