@extends('layouts.app')
@section('title', 'Timesheets')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $hm = fn ($m) => sprintf('%d:%02d', intdiv((int) $m, 60), ((int) $m) % 60);
    $time = fn ($t) => $t ? $t->format('H:i') : '—';
    $statuses = ['absent' => 'Absent (expected, not present)', 'late' => 'Late', 'ot' => 'Has overtime', 'unapproved_ot' => 'Overtime not approved', 'missing' => 'No longer in MiHCM'];
@endphp
<x-page-header title="Timesheets" description="Daily attendance as last loaded from MiHCM. Data for the current month may still change." />

<x-card :padding="false">
    <form method="GET" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-6">
        <input type="date" name="from" value="{{ $from->toDateString() }}" class="{{ $field }}" aria-label="From date">
        <input type="date" name="to" value="{{ $to->toDateString() }}" class="{{ $field }}" aria-label="To date">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, employee no" class="{{ $field }} lg:col-span-2">
        <x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="request('scheme')" empty="All schemes" />
        <div class="flex gap-2">
            <select name="status" class="{{ $field }}"><option value="">All days</option>
                @foreach ($statuses as $k => $t)<option value="{{ $k }}" @selected($status === $k)>{{ $t }}</option>@endforeach</select>
            <x-button type="submit" variant="secondary">Filter</x-button>
        </div>
    </form>

    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Date</th><th class="px-5 py-3 font-medium">In – out</th>
                <th class="px-5 py-3 text-right font-medium">Worked</th><th class="px-5 py-3 text-right font-medium">Late</th>
                <th class="px-5 py-3 text-right font-medium">Overtime</th><th class="px-5 py-3 font-medium">Leave</th><th class="px-5 py-3 font-medium">Status</th>
            </tr>
        </x-slot:head>
        @forelse ($records as $r)
            @php $e = $employees[$r->employee_id] ?? null; $ot = $r->ot_minutes + $r->double_ot_minutes + $r->triple_ot_minutes; @endphp
            <tr @class(['bg-slate-50/60 text-slate-500' => ! $r->expected, 'opacity-60' => $r->missing_since])>
                <td class="px-5 py-3">
                    @if ($e)<a href="{{ route('employees.show', [$e, 'tab' => 'attendance']) }}" class="font-medium text-slate-900 hover:underline">{{ $e->display_name ?? $e->full_name }}</a>
                    @else<span class="font-medium text-slate-900">Unknown</span>@endif
                    <p class="text-xs text-slate-500">{{ $r->employee_no }}</p>
                </td>
                <td class="whitespace-nowrap px-5 py-3">{{ $r->work_date->format('d M Y') }}<span class="block text-xs text-slate-500">{{ $r->work_date->format('l') }}@unless ($r->expected) · not expected @endunless</span></td>
                <td class="whitespace-nowrap px-5 py-3">{{ $time($r->in_time) }} – {{ $time($r->out_time) }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $r->work_minutes ? $hm($r->work_minutes) : '—' }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $r->late_minutes ? $hm($r->late_minutes) : '—' }}</td>
                <td class="px-5 py-3 text-right font-mono">{{ $ot ? $hm($ot) : '—' }}</td>
                <td class="px-5 py-3 text-xs">{{ $r->leave_name ?? '—' }}</td>
                <td class="px-5 py-3">
                    <div class="flex flex-wrap gap-1">
                        @if ($r->missing_since)<x-badge color="red">No longer in MiHCM</x-badge>
                        @elseif ($r->expected && ! $r->present)<x-badge color="amber">Absent</x-badge>
                        @elseif ($r->present)<x-badge color="green">Present</x-badge>@endif
                        @if ($ot && ! $r->approval_done)<x-badge color="amber">OT not approved</x-badge>@endif
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="px-5 py-10 text-center text-slate-500">No attendance found. Load it from Time &amp; Attendance &gt; Load data.</td></tr>
        @endforelse
    </x-table>
    @if ($records->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $records->links() }}</div>@endif
</x-card>
@endsection
