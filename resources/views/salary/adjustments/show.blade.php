@extends('layouts.app')
@section('title', 'Adjustment #'.$batch->id)

@section('content')
@php
    $color = ['staged' => 'amber', 'applied' => 'green', 'rolled_back' => 'red', 'discarded' => 'slate'];
    $rowColor = ['valid' => 'green', 'error' => 'red', 'applied' => 'brand'];
    $modes = \App\Payroll\SalaryAdjustment::MODES;
    $field = 'mt-3 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $canReveal = auth()->user()->can('employee-salary.reveal');
    $ids = $rows->pluck('id')->all();
    $staged = $batch->status === 'staged';
@endphp
<x-page-header :title="'Salary adjustment #'.$batch->id" :description="$batch->scheme->name.' · effective '.$batch->effective_from->format('d M Y').' · '.($batch->source === 'import' ? 'Excel: '.$batch->file_name : 'entered on screen').' · '.$batch->created_by_name">
    <x-slot:actions>
        <x-badge :color="$color[$batch->status] ?? 'slate'">{{ \App\Models\SalaryAdjustmentBatch::STATUSES[$batch->status] ?? $batch->status }}</x-badge>
        <x-button :href="route('salary-adjustments.index')" variant="secondary">All adjustments</x-button>
        @if ($staged && $canAct)
            <x-confirm-action :action="route('salary-adjustments.discard', $batch)" title="Discard this adjustment?" confirm="Discard">
                <x-slot:trigger><x-button variant="secondary">Discard</x-button></x-slot:trigger>
                Nothing has changed. You can prepare a corrected one.
            </x-confirm-action>
            @if ($batch->valid_rows > 0)
                <x-confirm-action :action="route('salary-adjustments.apply', $batch)" :title="'Apply '.$batch->valid_rows.' amounts?'" confirm="Apply adjustment" variant="primary" phrase="APPLY">
                    <x-slot:trigger><x-button>Apply {{ $batch->valid_rows }} {{ Str::plural('amount', $batch->valid_rows) }}</x-button></x-slot:trigger>
                    Each employee gets one new salary version effective {{ $batch->effective_from->format('d M Y') }}. Only the valid rows are applied, all together. @if ($batch->error_rows)The {{ $batch->error_rows }} rows with errors are skipped: fix them and prepare them again. @endif
                </x-confirm-action>
            @endif
        @elseif ($batch->status === 'applied' && $canRollback)
            <x-confirm-action :action="route('salary-adjustments.rollback', $batch)" title="Roll back this adjustment?" confirm="Roll back" phrase="ROLLBACK">
                <x-slot:trigger><x-button variant="secondary"><span class="text-red-600">Roll back</span></x-button></x-slot:trigger>
                The old amounts are restored by adding new salary versions (the history is kept). If the salary of ANY employee in this adjustment was changed afterwards, nothing is rolled back.
                <input type="text" name="reason" maxlength="500" required placeholder="Reason" class="{{ $field }}">
            </x-confirm-action>
        @endif
    </x-slot:actions>
</x-page-header>

<div class="mb-4 space-y-3">
    <p class="text-sm text-slate-600"><span class="font-medium text-slate-900">Reason:</span> {{ $batch->reason }}</p>
    @if ($batch->status === 'rolled_back')<x-alert type="warning">Rolled back on {{ $batch->rolled_back_at?->format('d M Y H:i') }} by {{ $batch->rolled_back_by_name }}: {{ $batch->rolled_back_reason }}</x-alert>@endif
    @if ($batch->notes)<x-alert type="info" title="Notes">{!! nl2br(e($batch->notes)) !!}</x-alert>@endif
    @if ($staged && ! $canAct)<x-alert type="info">You can see this adjustment but not apply it: {{ $batch->source === 'import' ? 'applying an Excel upload' : 'applying an adjustment' }} needs another permission.</x-alert>@endif
    @if ($batch->status === 'applied')<x-alert type="info">Applied on {{ $batch->applied_at?->format('d M Y H:i') }} by {{ $batch->applied_by_name }}: {{ $batch->applied_rows }} amount(s) changed for {{ $batch->employees_changed }} employee(s), {{ $batch->unchanged_rows }} unchanged.</x-alert>@endif
</div>

<div class="mb-6 grid gap-4 sm:grid-cols-4">
    <x-stat-card label="Rows" :value="number_format($batch->total_rows)" />
    <x-stat-card label="Valid" :value="number_format($batch->valid_rows)" />
    <x-stat-card label="With errors" :value="number_format($batch->error_rows)" :trend="$batch->error_rows ? 'down' : 'up'" :delta="$batch->error_rows ? 'These are skipped' : null" />
    <x-stat-card label="Rows with notes" :value="number_format($warningRows)" trend="down" />
</div>

<div x-data="{ vals: null, loading: false, timer: null,
        async reveal() {
            this.loading = true;
            try {
                const r = await fetch(@js(route('salary-adjustments.reveal', ['batch' => $batch, 'rows' => implode(',', $ids)])), { headers: { Accept: 'application/json' } });
                if (!r.ok) throw new Error();
                const j = await r.json();
                this.vals = j.rows;
                this.timer = setTimeout(() => this.vals = null, (j.seconds || 20) * 1000);
            } catch (e) { window.toast('Could not load the amounts.', 'error'); }
            this.loading = false;
        },
        hide() { this.vals = null; clearTimeout(this.timer); },
        fmt(v) { if (v === null || v === undefined) return '—'; const s = String(v); const neg = s.startsWith('-'); const p = s.replace('-', '').split('.'); return (neg ? '-' : '') + p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (p[1] || '').padEnd(2, '0'); } }">
    <x-card title="Rows" :padding="false">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex flex-wrap gap-1 text-xs">
                    <a href="{{ route('salary-adjustments.show', $batch) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => ! $status, 'hover:bg-slate-100' => $status])>All</a>
                    @foreach (['error' => 'Errors', 'valid' => 'Valid', 'applied' => 'Applied'] as $k => $t)
                        <a href="{{ route('salary-adjustments.show', [$batch, 'status' => $k]) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => $status === $k, 'hover:bg-slate-100' => $status !== $k])>{{ $t }}</a>
                    @endforeach
                </div>
                @if ($canReveal && count($ids))
                    <x-button variant="secondary" size="sm" x-show="vals === null" @click="reveal()" x-bind:disabled="loading">Show amounts</x-button>
                    <x-button variant="secondary" size="sm" x-show="vals !== null" x-cloak @click="hide()">Hide amounts</x-button>
                @endif
            </div>
        </x-slot:actions>
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">Row</th><th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Component</th><th class="px-5 py-3 font-medium">How</th>
                    <th class="px-5 py-3 text-right font-medium">Value</th><th class="px-5 py-3 text-right font-medium">Current</th><th class="px-5 py-3 text-right font-medium">New</th><th class="px-5 py-3 font-medium">Result</th></tr>
            </x-slot:head>
            @forelse ($rows as $r)
                @php $d = $r->data; $isPct = ($d['mode'] ?? '') === 'percent'; @endphp
                <tr>
                    <td class="px-5 py-3 text-slate-500">{{ $r->row_no }}</td>
                    <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $d['employee_name'] ?? '—' }}</span><p class="text-xs text-slate-500">{{ $d['employee_no'] ?? '' }}</p></td>
                    <td class="px-5 py-3 font-mono text-xs">{{ $d['component_code'] ?? '' }}</td>
                    <td class="px-5 py-3 text-sm">{{ $modes[$d['mode'] ?? ''] ?? '—' }}</td>
                    <td class="px-5 py-3 text-right font-mono">
                        @if (($d['value'] ?? null) === null)—
                        @elseif ($isPct){{ $d['value'][0] === '-' ? '' : '+' }}{{ $d['value'] }}%
                        @else<span x-show="vals === null" class="text-slate-500">••••••</span><span x-show="vals !== null" x-cloak x-text="vals && vals[{{ $r->id }}] ? fmt(vals[{{ $r->id }}].value) : ''"></span>@endif
                    </td>
                    <td class="px-5 py-3 text-right font-mono">@if (($d['current'] ?? null) === null)—@else<span x-show="vals === null" class="text-slate-500">••••••</span><span x-show="vals !== null" x-cloak x-text="vals && vals[{{ $r->id }}] ? fmt(vals[{{ $r->id }}].current) : ''"></span>@endif</td>
                    <td class="px-5 py-3 text-right font-mono font-medium">@if (($d['new'] ?? null) === null)—@else<span x-show="vals === null" class="text-slate-500">••••••</span><span x-show="vals !== null" x-cloak x-text="vals && vals[{{ $r->id }}] ? fmt(vals[{{ $r->id }}].new) : ''"></span>@endif</td>
                    <td class="px-5 py-3">
                        <x-badge :color="$rowColor[$r->status] ?? 'slate'">{{ ucfirst($r->status) }}</x-badge>
                        @foreach ($r->errors ?? [] as $e)<p class="mt-1 text-xs text-red-700">{{ $e }}</p>@endforeach
                        @foreach ($r->warnings ?? [] as $w)<p class="mt-1 text-xs text-amber-700">{{ $w }}</p>@endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-5 py-10 text-center text-slate-500">No rows.</td></tr>
            @endforelse
        </x-table>
        @if ($rows->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $rows->links() }}</div>@endif
    </x-card>
</div>
@endsection
