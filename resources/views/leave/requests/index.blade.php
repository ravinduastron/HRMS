@extends('layouts.app')
@section('title', 'Leave requests')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $typeMap = $types->keyBy('code');
    $session = ['FH' => 'first half', 'SH' => 'second half'];
@endphp
<x-page-header title="Leave requests" description="Leave as last loaded from MiHCM. Only confirmed leave can be deducted from pay." />

<x-card :padding="false">
    <form method="GET" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-6">
        <input type="date" name="from" value="{{ $from->toDateString() }}" class="{{ $field }}" aria-label="From date">
        <input type="date" name="to" value="{{ $to->toDateString() }}" class="{{ $field }}" aria-label="To date">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, employee no" class="{{ $field }}">
        <x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="request('scheme')" empty="All schemes" />
        <x-combobox name="type" :options="$types->map(fn ($t) => ['value' => $t->code, 'label' => $t->name])->values()" :value="request('type')" empty="All leave types" />
        <div class="flex gap-2">
            <select name="status" class="{{ $field }}"><option value="">Any status</option>
                <option value="C" @selected($status === 'C')>Confirmed</option><option value="P" @selected($status === 'P')>Pending</option></select>
            <x-button type="submit" variant="secondary">Filter</x-button>
        </div>
    </form>

    <x-table>
        <x-slot:head>
            <tr><th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Leave type</th><th class="px-5 py-3 font-medium">From – to</th>
                <th class="px-5 py-3 text-right font-medium">Days</th><th class="px-5 py-3 font-medium">Status</th></tr>
        </x-slot:head>
        @forelse ($entries as $l)
            @php $e = $employees[$l->employee_id] ?? null; $t = $typeMap[$l->leave_type_code] ?? null; @endphp
            <tr @class(['opacity-60' => $l->missing_since])>
                <td class="px-5 py-3">
                    @if ($e)<a href="{{ route('employees.show', [$e, 'tab' => 'leave']) }}" class="font-medium text-slate-900 hover:underline">{{ $e->display_name ?? $e->full_name }}</a>
                    @else<span class="font-medium text-slate-900">Unknown</span>@endif
                    <p class="text-xs text-slate-500">{{ $l->employee_no }}</p>
                </td>
                <td class="px-5 py-3">{{ $l->leave_type_name }}
                    <span class="ml-1">@if (! $t || $t->needsReview())<x-badge color="amber">Needs review</x-badge>@elseif ($t->is_no_pay)<x-badge color="red">No-pay</x-badge>@endif</span></td>
                <td class="whitespace-nowrap px-5 py-3">{{ $l->start_date->format('d M Y') }}@if (! $l->start_date->isSameDay($l->end_date)) – {{ $l->end_date->format('d M Y') }}@endif
                    @if ($l->start_date->isSameDay($l->end_date) && $l->days < 1)<span class="block text-xs text-slate-500">{{ $session[$l->start_session] ?? '' }}</span>@endif</td>
                <td class="px-5 py-3 text-right">{{ rtrim(rtrim(number_format($l->days, 2), '0'), '.') }}</td>
                <td class="px-5 py-3">
                    @if ($l->missing_since)<x-badge color="red">Cancelled in MiHCM</x-badge>
                    @elseif ($l->status === 'C')<x-badge color="green">Confirmed</x-badge>
                    @else<x-badge color="amber">Pending</x-badge>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No leave found for this range. Load it from Time &amp; Attendance &gt; Load data.</td></tr>
        @endforelse
    </x-table>
    @if ($entries->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $entries->links() }}</div>@endif
</x-card>
@endsection
