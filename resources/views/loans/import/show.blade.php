@extends('layouts.app')
@section('title', 'Import #'.$batch->id)

@section('content')
@php
    $color = ['staged' => 'amber', 'applied' => 'green', 'reversed' => 'red', 'discarded' => 'slate'];
    $rowColor = ['valid' => 'green', 'error' => 'red', 'created' => 'brand'];
    $m = fn ($v) => $v === null || $v === '' ? '—' : \App\Payroll\Statutory\Format::money((string) $v);
    $field = 'mt-3 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
@endphp
<x-page-header :title="'Import #'.$batch->id" :description="$batch->file_name.' · '.$batch->uploaded_by_name.' · '.$batch->created_at->format('d M Y H:i')">
    <x-slot:actions>
        <x-badge :color="$color[$batch->status] ?? 'slate'">{{ ucfirst($batch->status) }}</x-badge>
        <x-button :href="route('loans.import.index')" variant="secondary">All uploads</x-button>
        @if ($batch->status === 'staged')
            <x-confirm-action :action="route('loans.import.discard', $batch)" title="Discard this upload?" confirm="Discard">
                <x-slot:trigger><x-button variant="secondary">Discard</x-button></x-slot:trigger>Nothing has been created. You can upload a corrected file.
            </x-confirm-action>
            @if ($batch->valid_rows > 0)
                <x-confirm-action :action="route('loans.import.apply', $batch)" :title="'Create '.$batch->valid_rows.' loans?'" confirm="Create loans" variant="primary" phrase="CREATE">
                    <x-slot:trigger><x-button>Create {{ $batch->valid_rows }} {{ Str::plural('loan', $batch->valid_rows) }}</x-button></x-slot:trigger>
                    Only the valid rows are created, all together. @if ($batch->error_rows)The {{ $batch->error_rows }} rows with errors are skipped: fix them and upload them again. @endif
                </x-confirm-action>
            @endif
        @elseif ($batch->status === 'applied')
            <x-confirm-action :action="route('loans.import.reverse', $batch)" title="Reverse this upload?" confirm="Reverse upload">
                <x-slot:trigger><x-button variant="secondary"><span class="text-red-600">Reverse</span></x-button></x-slot:trigger>
                The loans it created are cancelled. This is only possible while none of them has had a deduction, a rate change or a settlement.
                <input type="text" name="reason" maxlength="500" required placeholder="Reason" class="{{ $field }}">
            </x-confirm-action>
        @endif
    </x-slot:actions>
</x-page-header>

@if ($batch->status === 'reversed')<x-alert type="warning" class="mb-4">Reversed on {{ $batch->reversed_at?->format('d M Y H:i') }}: {{ $batch->reversed_reason }}</x-alert>@endif

<div class="mb-6 grid gap-4 sm:grid-cols-4">
    <x-stat-card label="Rows in the file" :value="number_format($batch->total_rows)" />
    <x-stat-card label="Valid" :value="number_format($batch->valid_rows)" />
    <x-stat-card label="With errors" :value="number_format($batch->error_rows)" :trend="$batch->error_rows ? 'down' : 'up'" :delta="$batch->error_rows ? 'These are skipped' : null" />
    <x-stat-card label="Loans created" :value="number_format($batch->loans_created)" :delta="$warningRows ? $warningRows.' rows have warnings' : null" trend="down" />
</div>

<x-card title="Rows" :padding="false">
    <x-slot:actions>
        <div class="flex flex-wrap gap-1 text-xs">
            <a href="{{ route('loans.import.show', $batch) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => ! $status, 'hover:bg-slate-100' => $status])>All</a>
            @foreach (['error' => 'Errors', 'valid' => 'Valid', 'created' => 'Created'] as $k => $t)
                <a href="{{ route('loans.import.show', [$batch, 'status' => $k]) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => $status === $k, 'hover:bg-slate-100' => $status !== $k])>{{ $t }}</a>
            @endforeach
        </div>
    </x-slot:actions>
    <x-table>
        <x-slot:head>
            <tr><th class="px-5 py-3 font-medium">Row</th><th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Loan</th>
                <th class="px-5 py-3 text-right font-medium">Outstanding</th><th class="px-5 py-3 text-right font-medium">Left</th><th class="px-5 py-3 font-medium">Result</th></tr>
        </x-slot:head>
        @forelse ($rows as $r)
            @php $d = $r->data; @endphp
            <tr>
                <td class="px-5 py-3 text-slate-500">{{ $r->row_no }}</td>
                <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $d['employee_name'] ?? '—' }}</span><p class="text-xs text-slate-500">{{ $d['employee_no'] ?? '' }}</p></td>
                <td class="px-5 py-3">{{ $d['reference'] ?? '' }}<p class="text-xs text-slate-500">{{ $d['loan_type'] ?? '' }} · {{ \App\Payroll\Loans\LoanSchedule::METHODS[$d['method'] ?? ''] ?? '—' }} · {{ $d['rate'] ?? '—' }}%</p></td>
                <td class="px-5 py-3 text-right font-mono">{{ $m($d['outstanding'] ?? null) }}</td>
                <td class="px-5 py-3 text-right">{{ $d['remaining'] ?? '—' }}@if (! empty($d['next_month']))<p class="text-xs text-slate-500">from {{ \Illuminate\Support\Carbon::parse($d['next_month'])->format('M Y') }}</p>@endif</td>
                <td class="px-5 py-3">
                    <x-badge :color="$rowColor[$r->status] ?? 'slate'">{{ ucfirst($r->status) }}</x-badge>
                    @if ($r->loan_id)<a href="{{ route('loans.show', $r->loan_id) }}" class="ml-1 text-xs text-brand-700 hover:underline">Open loan</a>@endif
                    @foreach ($r->errors ?? [] as $e)<p class="mt-1 text-xs text-red-700">{{ $e }}</p>@endforeach
                    @foreach ($r->warnings ?? [] as $w)<p class="mt-1 text-xs text-amber-700">{{ $w }}</p>@endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No rows.</td></tr>
        @endforelse
    </x-table>
    @if ($rows->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>@endif
</x-card>
@endsection
