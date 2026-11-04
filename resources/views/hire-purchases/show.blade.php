@extends('layouts.app')
@section('title', 'Hire purchase '.$purchase->reference)

@section('content')
@php
    $m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
    $field = 'mt-1.5 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $color = ['active' => 'green', 'completed' => 'slate', 'stopped' => 'red'];
    $inst = ['pending' => ['Pending', 'amber'], 'deducted' => ['Deducted', 'green'], 'stopped' => ['Stopped', 'red'], 'replaced' => ['Replaced by a change', 'slate']];
    $active = $purchase->status === 'active';
    $next = $summary['next'];
    $nextMonth = ($next?->due_month ?? today())->format('Y-m');
    $e = $purchase->employee;
@endphp
<x-page-header :title="'Hire purchase '.$purchase->reference" :description="($e->display_name ?? $e->full_name).' · '.$purchase->vendor->name">
    <x-slot:actions>
        <x-badge :color="$color[$purchase->status] ?? 'slate'">{{ ucfirst($purchase->status) }}</x-badge>
        <x-button :href="route('hire-purchases.index')" variant="secondary">All hire purchases</x-button>
        @if ($active)
            @can('hire-purchases.stop')
                <x-confirm-action :action="route('hire-purchases.stop', $purchase)" title="Stop the deductions?" confirm="Stop deductions" phrase="STOP">
                    <x-slot:trigger><x-button variant="secondary"><span class="text-red-600">Stop deductions</span></x-button></x-slot:trigger>
                    All {{ $summary['count'] }} unpaid installment(s) will be marked stopped and nothing more is deducted from pay. This is final: to deduct again, enter a new hire purchase. Installments already deducted are kept.
                    <input type="text" name="reason" maxlength="500" required placeholder="Reason (for example: employee resigned)" class="{{ $field }}">
                </x-confirm-action>
            @endcan
        @endif
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    @if ($active && $left)
        <x-alert type="warning" title="This employee has left or is inactive">{{ $summary['count'] }} installment(s) are still due. Nothing is stopped automatically. @can('hire-purchases.stop')Use "Stop deductions" if they should not be recovered from pay.@endcan</x-alert>
    @endif

    <x-card title="Summary">
        <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-slate-500">Employee</dt><dd class="mt-0.5 font-medium text-slate-900"><a href="{{ route('employees.show', $e) }}" class="hover:underline">{{ $e->display_name ?? $e->full_name }}</a>
                <span class="block text-xs font-normal text-slate-500">{{ $e->employee_no }}@if ($e->scheme) · {{ $e->scheme->name }}@endif</span></dd></div>
            <div><dt class="text-slate-500">Vendor</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $purchase->vendor->name }}<span class="block text-xs font-normal text-slate-500">{{ $purchase->vendor->code }}</span></dd></div>
            <div class="sm:col-span-2"><dt class="text-slate-500">Item</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $purchase->item_description }}</dd></div>
            <div><dt class="text-slate-500">Started as</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $m($purchase->installment_amount) }} × {{ $purchase->term_months }}<span class="block text-xs font-normal text-slate-500">from {{ $purchase->first_due_month->format('M Y') }}</span></dd></div>
            <div><dt class="text-slate-500">Deducted as</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $purchase->component->code }}<span class="block text-xs font-normal text-slate-500">{{ $purchase->component->name }}</span></dd></div>
            <div><dt class="text-slate-500">Next installment</dt><dd class="mt-0.5 font-medium text-slate-900">@if ($next){{ $m($next->amount) }}<span class="block text-xs font-normal text-slate-500">{{ $next->due_month->format('M Y') }}</span>@else — @endif</dd></div>
            <div><dt class="text-slate-500">Left to deduct</dt><dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ $active ? $m($summary['total']) : '—' }}<span class="block text-xs font-normal text-slate-500">{{ $active ? $summary['count'].' '.\Illuminate\Support\Str::plural('installment', $summary['count']) : '' }}</span></dd></div>
            @if ($purchase->status !== 'active')
                <div class="sm:col-span-2"><dt class="text-slate-500">Closed</dt><dd class="mt-0.5 font-medium text-slate-900">
                    @if ($purchase->status === 'stopped'){{ $purchase->stopped_on?->format('d M Y') }} · {{ $purchase->closed_reason }}@else Fully deducted @endif</dd></div>
            @endif
            @if ($purchase->notes)<div class="sm:col-span-2"><dt class="text-slate-500">Notes</dt><dd class="mt-0.5 text-slate-900">{{ $purchase->notes }}</dd></div>@endif
        </dl>
    </x-card>

    @if ($active && $pending->isNotEmpty())
        @can('hire-purchases.manage')
        <x-card title="Change the future deductions">
            <form method="POST" action="{{ route('hire-purchases.change.store', $purchase) }}" class="space-y-4"
                x-data="{ month: @js(old('effective_month', $nextMonth)), amount: @js(old('new_amount', '')), count: @js(old('new_count', '')), restart: @js(old('restart_month', '')), res: null, busy: false,
                    async preview() {
                        this.busy = true; this.res = null;
                        try {
                            const r = await fetch(@js(route('hire-purchases.change.preview', $purchase)), { method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ effective_month: this.month, new_amount: this.amount, new_count: this.count, restart_month: this.restart || null }) });
                            const j = await r.json();
                            this.res = r.status === 422 ? { error: Object.values(j.errors ?? {}).flat()[0] ?? 'Check the values.' } : j;
                        } catch (e) { this.res = { error: 'Could not calculate.' }; }
                        this.busy = false;
                    } }">
                @csrf
                <p class="text-sm text-slate-500">The unpaid installments from the chosen month are replaced by the new amount and the new number of installments. Installments already deducted are not touched. To skip months, choose a later month under "Deduct again from". The old installments stay visible as an earlier version, and the change is kept in the history below.</p>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <x-input name="effective_month" type="month" label="Change applies from" x-model="month" required />
                    <x-input name="new_amount" label="New installment amount (Rs)" x-model="amount" inputmode="decimal" required :hint="$next ? 'Now '.$m($next->amount) : null" />
                    <x-input name="new_count" type="number" min="1" max="120" label="Installments from then on" x-model="count" required :hint="'Now '.$summary['count'].' left'" />
                    <x-input name="restart_month" type="month" label="Deduct again from" x-model="restart" hint="Optional. Empty = the same month." />
                </div>
                <x-input name="change_note" label="Reason" :value="old('change_note')" required />
                <div class="flex flex-wrap items-center gap-3">
                    <x-button variant="secondary" @click="preview()" x-bind:disabled="busy">Preview the effect</x-button>
                    <x-button type="submit">Apply the change</x-button>
                </div>
                <div x-show="res" x-cloak>
                    <p x-show="res && res.error" class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="res && res.error"></p>
                    <table x-show="res && !res.error" class="min-w-full text-left text-sm">
                        <thead class="text-xs text-slate-500"><tr><th class="py-2 pr-4 font-medium">From the chosen month</th><th class="py-2 pr-4 text-right font-medium">Before</th><th class="py-2 text-right font-medium">After</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr><td class="py-2 pr-4 text-slate-600">Installment</td><td class="py-2 pr-4 text-right font-mono" x-text="res && res.old_amount"></td><td class="py-2 text-right font-mono font-medium" x-text="res && res.new_amount"></td></tr>
                            <tr><td class="py-2 pr-4 text-slate-600">Number of installments</td><td class="py-2 pr-4 text-right font-mono" x-text="res && res.old_count"></td><td class="py-2 text-right font-mono font-medium" x-text="res && res.new_count"></td></tr>
                            <tr><td class="py-2 pr-4 text-slate-600">Months</td><td class="py-2 pr-4 text-right text-xs" x-text="res && res.old_range"></td><td class="py-2 text-right text-xs font-medium" x-text="res && res.new_range"></td></tr>
                            <tr><td class="py-2 pr-4 text-slate-600">Still to be deducted</td><td class="py-2 pr-4 text-right font-mono" x-text="res && res.old_total"></td><td class="py-2 text-right font-mono font-medium" x-text="res && res.new_total"></td></tr>
                        </tbody>
                    </table>
                </div>
            </form>
        </x-card>
        @endcan
    @endif

    <x-card title="Change history" :padding="false">
        @if ($changes->isEmpty())
            <p class="px-5 py-4 text-sm text-slate-500">The future deductions have not been changed.</p>
        @else
            <x-table>
                <x-slot:head>
                    <tr><th class="px-5 py-3 font-medium">Applies from</th><th class="px-5 py-3 text-right font-medium">Installment</th><th class="px-5 py-3 text-right font-medium">Number</th>
                        <th class="px-5 py-3 text-right font-medium">Still to deduct</th><th class="px-5 py-3 font-medium">Changed by</th></tr>
                </x-slot:head>
                @foreach ($changes as $c)
                    <tr>
                        <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ $c->effective_month->format('M Y') }}
                            @if ($c->restart_month->gt($c->effective_month))<span class="block text-xs font-normal text-slate-500">deducted again from {{ $c->restart_month->format('M Y') }}</span>@endif</td>
                        <td class="px-5 py-3 text-right font-mono text-xs">{{ $m($c->old_amount) }} → {{ $m($c->new_amount) }}</td>
                        <td class="px-5 py-3 text-right font-mono text-xs">{{ $c->old_remaining }} → {{ $c->new_remaining }}</td>
                        <td class="px-5 py-3 text-right font-mono text-xs">{{ $m($c->old_total) }} → {{ $m($c->new_total) }}</td>
                        <td class="px-5 py-3 text-slate-500">{{ $c->created_by_name ?? 'System' }}<span class="block text-xs">{{ $c->created_at->format('d M Y H:i') }} · {{ $c->change_note }}</span></td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>

    <x-card title="Deduction schedule" :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">#</th><th class="px-5 py-3 font-medium">Due</th><th class="px-5 py-3 text-right font-medium">Amount</th><th class="px-5 py-3 font-medium">Status</th></tr>
            </x-slot:head>
            @forelse ($schedule as $i)
                <tr @class(['text-slate-400' => $i->status === 'stopped'])>
                    <td class="px-5 py-2.5">{{ $i->seq }}</td>
                    <td class="whitespace-nowrap px-5 py-2.5">{{ $i->due_month->format('M Y') }}</td>
                    <td class="px-5 py-2.5 text-right font-mono font-medium">{{ $m($i->amount) }}</td>
                    <td class="px-5 py-2.5"><x-badge :color="$inst[$i->status][1] ?? 'slate'">{{ $inst[$i->status][0] ?? $i->status }}</x-badge>@if ($i->deducted_on)<span class="ml-1 text-xs text-slate-500">{{ $i->deducted_on->format('d M Y') }}</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-5 py-8 text-center text-slate-500">No installments.</td></tr>
            @endforelse
        </x-table>
        @if ($earlier->isNotEmpty())
            <details class="border-t border-slate-200 px-5 py-3 text-sm">
                <summary class="cursor-pointer font-medium text-brand-700">Earlier versions of the schedule ({{ $earlier->count() }} installments replaced by changes)</summary>
                <table class="mt-3 min-w-full text-left text-xs text-slate-600">
                    <thead><tr><th class="py-1.5 pr-4 font-medium">Version</th><th class="py-1.5 pr-4 font-medium">#</th><th class="py-1.5 pr-4 font-medium">Due</th><th class="py-1.5 text-right font-medium">Amount</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($earlier as $i)
                            <tr><td class="py-1.5 pr-4">{{ $i->schedule_version }}</td><td class="py-1.5 pr-4">{{ $i->seq }}</td><td class="py-1.5 pr-4">{{ $i->due_month->format('M Y') }}</td><td class="py-1.5 text-right font-mono">{{ $m($i->amount) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif
    </x-card>
</div>
@endsection
