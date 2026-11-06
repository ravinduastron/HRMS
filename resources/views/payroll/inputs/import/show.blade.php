@extends('layouts.app')
@section('title', 'Upload #'.$batch->id)

@section('content')
@php
    $color = ['staged' => 'amber', 'applied' => 'green', 'reversed' => 'red', 'discarded' => 'slate'];
    $rowColor = ['valid' => 'green', 'error' => 'red', 'applied' => 'brand'];
    $m = fn ($v) => $v === null || $v === '' ? '—' : \App\Payroll\Statutory\Format::money((string) $v);
    $field = 'mt-3 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $open = $batch->period?->isOpen();
@endphp
<x-page-header :title="'Upload #'.$batch->id" :description="$batch->file_name.' · '.($batch->period?->label()).' · '.$batch->uploaded_by_name.' · '.$batch->created_at->format('d M Y H:i')">
    <x-slot:actions>
        <x-badge :color="$color[$batch->status] ?? 'slate'">{{ ucfirst($batch->status) }}</x-badge>
        <x-button :href="route('pay-inputs.import.index', ['scheme' => $batch->scheme_id, 'period' => $batch->pay_period_id])" variant="secondary">All uploads</x-button>
        @if ($batch->status === 'staged')
            <x-confirm-action :action="route('pay-inputs.import.discard', $batch)" title="Discard this upload?" confirm="Discard">
                <x-slot:trigger><x-button variant="secondary">Discard</x-button></x-slot:trigger>Nothing has changed. You can upload a corrected file.
            </x-confirm-action>
            @if ($batch->valid_rows > 0 && $open)
                <x-confirm-action :action="route('pay-inputs.import.apply', $batch)" :title="'Apply '.$batch->valid_rows.' amounts?'" confirm="Apply amounts" variant="primary" phrase="APPLY">
                    <x-slot:trigger><x-button>Apply {{ $batch->valid_rows }} {{ Str::plural('amount', $batch->valid_rows) }}</x-button></x-slot:trigger>
                    Only the valid rows are applied, all together. Values already in force are replaced and the old ones stay in the history. @if ($batch->error_rows)The {{ $batch->error_rows }} rows with errors are skipped: fix them and upload them again. @endif
                </x-confirm-action>
            @endif
        @elseif ($batch->status === 'applied' && $open)
            <x-confirm-action :action="route('pay-inputs.import.reverse', $batch)" title="Reverse this upload?" confirm="Reverse upload">
                <x-slot:trigger><x-button variant="secondary"><span class="text-red-600">Reverse</span></x-button></x-slot:trigger>
                Every value it entered is removed and every value it replaced is put back. This is only possible while the pay period is open and none of its values was changed afterwards.
                <input type="text" name="reason" maxlength="500" required placeholder="Reason" class="{{ $field }}">
            </x-confirm-action>
        @endif
    </x-slot:actions>
</x-page-header>

@if ($batch->status === 'reversed')<x-alert type="warning" class="mb-4">Reversed on {{ $batch->reversed_at?->format('d M Y H:i') }}: {{ $batch->reversed_reason }}</x-alert>@endif
@if ($batch->status === 'staged' && ! $open)<x-alert type="info" class="mb-4">The pay period is {{ strtolower(\App\Models\PayPeriod::STATUSES[$batch->period?->status] ?? 'not open') }}, so this upload can no longer be applied.</x-alert>@endif
@if ($batch->status === 'applied' && ! $open)<x-alert type="info" class="mb-4">The pay period is no longer open, so this upload cannot be reversed.</x-alert>@endif

<div class="mb-6 grid gap-4 sm:grid-cols-4">
    <x-stat-card label="Amounts in the file" :value="number_format($batch->total_rows)" />
    <x-stat-card label="Valid" :value="number_format($batch->valid_rows)" />
    <x-stat-card label="With errors" :value="number_format($batch->error_rows)" :trend="$batch->error_rows ? 'down' : 'up'" :delta="$batch->error_rows ? 'These are skipped' : null" />
    <x-stat-card label="Changed when applied" :value="number_format($batch->applied_rows)" :delta="$batch->status === 'applied' ? $batch->unchanged_rows.' unchanged' : ($warningRows ? $warningRows.' rows have notes' : null)" trend="down" />
</div>

<x-card title="Rows" :padding="false">
    <x-slot:actions>
        <div class="flex flex-wrap gap-1 text-xs">
            <a href="{{ route('pay-inputs.import.show', $batch) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => ! $status, 'hover:bg-slate-100' => $status])>All</a>
            @foreach (['error' => 'Errors', 'valid' => 'Valid', 'applied' => 'Applied'] as $k => $t)
                <a href="{{ route('pay-inputs.import.show', [$batch, 'status' => $k]) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => $status === $k, 'hover:bg-slate-100' => $status !== $k])>{{ $t }}</a>
            @endforeach
        </div>
    </x-slot:actions>
    <x-table>
        <x-slot:head>
            <tr><th class="px-5 py-3 font-medium">Row</th><th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Component</th>
                <th class="px-5 py-3 text-right font-medium">Amount</th><th class="px-5 py-3 font-medium">Result</th></tr>
        </x-slot:head>
        @forelse ($rows as $r)
            @php $d = $r->data; @endphp
            <tr>
                <td class="px-5 py-3 text-slate-500">{{ $r->row_no }}</td>
                <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $d['employee_name'] ?? '—' }}</span><p class="text-xs text-slate-500">{{ $d['employee_no'] ?? '' }}</p></td>
                <td class="px-5 py-3 font-mono text-xs">{{ $d['component_code'] ?? '' }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($d['amount'] ?? null) }}</td>
                <td class="px-5 py-3">
                    <x-badge :color="$rowColor[$r->status] ?? 'slate'">{{ ucfirst($r->status) }}</x-badge>
                    @foreach ($r->errors ?? [] as $e)<p class="mt-1 text-xs text-red-700">{{ $e }}</p>@endforeach
                    @foreach ($r->warnings ?? [] as $w)<p class="mt-1 text-xs text-amber-700">{{ $w }}</p>@endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No rows.</td></tr>
        @endforelse
    </x-table>
    @if ($rows->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>@endif
</x-card>
@endsection
