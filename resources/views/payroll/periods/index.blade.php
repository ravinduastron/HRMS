@extends('layouts.app')
@section('title', 'Pay periods')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M Y');
    $short = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M');
    $color = ['open' => 'green', 'closed' => 'amber', 'locked' => 'slate'];
    $canClose = auth()->user()->can('pay-periods.close');
    $canReopen = auth()->user()->can('pay-periods.reopen');
    $canGenerate = auth()->user()->can('pay-periods.generate');
    $counts = $periods->countBy('status');
@endphp
<x-page-header title="Pay periods" description="The stored dates of each pay cycle of a scheme: what attendance is read from and when it is paid. Pay inputs and pay runs pick one of these.">
</x-page-header>

<div class="space-y-6">
    <x-card :padding="false">
        <form method="GET" class="flex flex-wrap items-end gap-3 p-4">
            <div class="w-64"><x-combobox name="scheme" label="Scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="$scheme?->id" /></div>
            <div class="space-y-1.5">
                <label for="year" class="block text-sm font-medium text-slate-700">Tax year</label>
                <select id="year" name="year" class="{{ $field }}">
                    @foreach ($years as $k => $t)<option value="{{ $k }}" @selected($year === $k)>{{ $t }}</option>@endforeach
                </select>
            </div>
            <x-button type="submit" variant="secondary">Show</x-button>
        </form>
    </x-card>

    @if (! $scheme)
        <x-alert type="warning">No schemes are allocated to you, so there are no pay periods to show.</x-alert>
    @else
        @unless ($hasSettings)
            <x-alert type="warning">{{ $scheme->name }} has no pay settings yet, so no periods can be generated. @can('schemes.view')<a href="{{ route('schemes.pay.index', $scheme) }}" class="font-medium underline">Set them up</a>.@endcan</x-alert>
        @endunless

        <x-card :title="$scheme->name.' · '.$years[$year]" :padding="false">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($counts->isNotEmpty())
                        <span class="text-sm text-slate-500">{{ $periods->count() }} periods · {{ $counts->get('open', 0) }} open · {{ $counts->get('closed', 0) }} closed · {{ $counts->get('locked', 0) }} locked</span>
                    @endif
                    @if ($canGenerate && $hasSettings)
                        <x-confirm-action :action="route('pay-periods.generate')" title="Generate the pay periods?" confirm="Generate" variant="primary">
                            <x-slot:trigger><x-button size="sm">Generate periods</x-button></x-slot:trigger>
                            <input type="hidden" name="scheme" value="{{ $scheme->id }}"><input type="hidden" name="year" value="{{ $year }}">
                            Creates every month and cycle of {{ $years[$year] }} for {{ $scheme->name }}, using the pay settings in force in each month. Periods that already exist are left as they are, so it is safe to repeat.
                        </x-confirm-action>
                        @if ($periods->isNotEmpty())
                            <x-confirm-action :action="route('pay-periods.generate')" title="Refresh the open periods?" confirm="Refresh open periods" variant="primary">
                                <x-slot:trigger><x-button size="sm" variant="secondary">Refresh open periods</x-button></x-slot:trigger>
                                <input type="hidden" name="scheme" value="{{ $scheme->id }}"><input type="hidden" name="year" value="{{ $year }}"><input type="hidden" name="refresh" value="1">
                                Open periods whose dates no longer match the current pay settings are updated, and open periods of a cycle the settings no longer have are removed. Closed and locked periods are never changed.
                            </x-confirm-action>
                        @endif
                    @endif
                </div>
            </x-slot:actions>

            <x-table>
                <x-slot:head>
                    <tr>
                        <th class="px-5 py-3 font-medium">Month</th><th class="px-5 py-3 font-medium">Period</th><th class="px-5 py-3 font-medium">Pay date between</th>
                        <th class="px-5 py-3 font-medium">Attendance windows</th><th class="px-5 py-3 font-medium">EPF / ETF</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th>
                    </tr>
                </x-slot:head>
                @forelse ($periods as $p)
                    <tr>
                        <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ $p->period_start->format('M Y') }}<span class="block text-xs font-normal text-slate-500">Cycle {{ $p->cycle_no }}</span></td>
                        <td class="whitespace-nowrap px-5 py-3">{{ $fmt($p->period_start) }} – {{ $fmt($p->period_end) }}</td>
                        <td class="whitespace-nowrap px-5 py-3 text-xs text-slate-600">{{ $fmt($p->pay_date_from) }} – {{ $fmt($p->pay_date_to) }}</td>
                        <td class="px-5 py-3 text-xs text-slate-600">
                            <details><summary class="cursor-pointer font-medium text-brand-700">Show</summary>
                                <ul class="mt-1 space-y-0.5">
                                    @foreach (\App\Payroll\PayCalendar::WINDOWS as $k => $t)
                                        @php $w = $p->windows[$k] ?? null; @endphp
                                        @if ($w)<li>{{ $t }}: {{ $short($w[0]) }} – {{ $short($w[1]) }}</li>@endif
                                    @endforeach
                                </ul>
                            </details>
                        </td>
                        <td class="px-5 py-3 text-xs">{{ $p->statutory_applies ? 'Deducted' : 'Not in this cycle' }}</td>
                        <td class="px-5 py-3"><x-badge :color="$color[$p->status] ?? 'slate'">{{ \App\Models\PayPeriod::STATUSES[$p->status] ?? $p->status }}</x-badge>
                            @if ($p->status === 'closed' && $p->closed_at)<span class="block text-xs text-slate-500">{{ $p->closed_at->format('d M Y H:i') }} · {{ $p->closed_by_name }}</span>@endif</td>
                        <td class="px-5 py-3">
                            <div class="flex justify-end gap-1">
                                @if ($p->status === 'open' && $canClose)
                                    <x-confirm-action :action="route('pay-periods.close', $p)" :title="'Close '.$p->label().'?'" confirm="Close period" variant="primary">
                                        <x-slot:trigger><x-button variant="ghost" size="sm">Close</x-button></x-slot:trigger>
                                        No more pay inputs can be added or changed for this period while it is closed. It can be reopened by someone with that permission, until a pay run locks it.
                                    </x-confirm-action>
                                @elseif ($p->status === 'closed' && $canReopen)
                                    <x-confirm-action :action="route('pay-periods.reopen', $p)" :title="'Reopen '.$p->label().'?'" confirm="Reopen period">
                                        <x-slot:trigger><x-button variant="ghost" size="sm">Reopen</x-button></x-slot:trigger>
                                        Pay inputs for this period can be changed again.
                                        <input type="text" name="reason" maxlength="500" required placeholder="Reason" class="mt-3 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600">
                                    </x-confirm-action>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">No pay periods for this tax year yet.@if ($canGenerate && $hasSettings) Use "Generate periods".@endif</td></tr>
                @endforelse
            </x-table>
        </x-card>
    @endif
</div>
@endsection
