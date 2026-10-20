{{-- Date range shared by the Attendance and Leave tabs. Vars: $employee, $attFrom, $attTo, $tabKey ('attendance' | 'leave') --}}
@php
    $rangeInput = 'mt-1.5 block w-44 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $rangeUrl = fn ($from, $to) => route('employees.show', ['employee' => $employee, 'tab' => $tabKey, 'att_from' => $from->toDateString(), 'att_to' => $to->toDateString()]);
@endphp
<form method="GET" action="{{ route('employees.show', $employee) }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
    <input type="hidden" name="tab" value="{{ $tabKey }}">
    <label class="block text-sm font-medium text-slate-700">From
        <input type="date" name="att_from" value="{{ $attFrom->toDateString() }}" required class="{{ $rangeInput }}">
    </label>
    <label class="block text-sm font-medium text-slate-700">To
        <input type="date" name="att_to" value="{{ $attTo->toDateString() }}" required class="{{ $rangeInput }}">
    </label>
    <x-button type="submit" variant="secondary">View</x-button>
    <div class="flex gap-3 pb-2 text-sm">
        <a href="{{ $rangeUrl(today()->startOfMonth(), today()) }}" class="font-medium text-brand-700 hover:underline">This month</a>
        <a href="{{ $rangeUrl(today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()) }}" class="font-medium text-brand-700 hover:underline">Last month</a>
    </div>
    <p class="pb-2 text-sm text-slate-500">Up to 100 days. Any dates work, for example an overtime window of 11 Aug to 10 Sep.</p>
</form>
