@extends('layouts.app')
@section('title', 'Loans & advances')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $m = fn ($v) => \App\Payroll\Statutory\Format::money($v);
    $color = ['active' => 'green', 'settled' => 'slate', 'cancelled' => 'red'];
@endphp
<x-page-header title="Loans & advances" description="Staff loans with their schedule, balance, rate history and early settlement.">
    <x-slot:actions>
        @can('loans.import')<x-button :href="route('loans.import.index')" variant="secondary">Import from Excel</x-button>@endcan
        @can('loans.manage')<x-button :href="route('loans.create')"><x-icon name="plus" class="size-4" /> New loan</x-button>@endcan
    </x-slot:actions>
</x-page-header>

<x-card :padding="false">
    <form method="GET" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-5">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, employee no, loan reference" class="{{ $field }} lg:col-span-2">
        <x-combobox name="type" :options="$types->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->values()" :value="request('type')" empty="All loan types" />
        <x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="request('scheme')" empty="All schemes" />
        <div class="flex gap-2">
            <select name="status" class="{{ $field }}"><option value="">Any status</option>
                @foreach (['active' => 'Active', 'settled' => 'Settled', 'cancelled' => 'Cancelled'] as $k => $t)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $t }}</option>@endforeach</select>
            <x-button type="submit" variant="secondary">Filter</x-button>
        </div>
    </form>

    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Loan</th><th class="px-5 py-3 font-medium">Interest</th>
                <th class="px-5 py-3 text-right font-medium">Outstanding</th><th class="px-5 py-3 text-right font-medium">Next installment</th>
                <th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th>
            </tr>
        </x-slot:head>
        @forelse ($loans as $l)
            @php $n = $next[$l->id] ?? null; @endphp
            <tr>
                <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $l->employee->display_name ?? $l->employee->full_name }}</span><p class="text-xs text-slate-500">{{ $l->employee->employee_no }}</p></td>
                <td class="px-5 py-3"><a href="{{ route('loans.show', $l) }}" class="font-medium text-slate-900 hover:underline">{{ $l->reference }}</a><p class="text-xs text-slate-500">{{ $l->type->name }}</p></td>
                <td class="px-5 py-3">{{ \App\Payroll\Decimal::plain((string) $l->current_rate) }}%<p class="text-xs text-slate-500">{{ \App\Payroll\Loans\LoanSchedule::METHODS[$l->interest_method] ?? $l->interest_method }}</p></td>
                <td class="px-5 py-3 text-right font-mono">{{ $l->status === 'active' ? $m($outstanding[$l->id] ?? '0') : '—' }}</td>
                <td class="px-5 py-3 text-right">@if ($n)<span class="font-mono">{{ $m($n->total()) }}</span><p class="text-xs text-slate-500">{{ $n->due_month->format('M Y') }}</p>@else<span class="text-slate-400">—</span>@endif</td>
                <td class="px-5 py-3"><x-badge :color="$color[$l->status] ?? 'slate'">{{ ucfirst($l->status) }}</x-badge>@if ($l->source === 'import')<x-badge color="brand" class="ml-1">Imported</x-badge>@endif</td>
                <td class="px-5 py-3 text-right"><x-button :href="route('loans.show', $l)" variant="ghost" size="sm">Open</x-button></td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">No loans found.</td></tr>
        @endforelse
    </x-table>
    @if ($loans->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $loans->links() }}</div>@endif
</x-card>
@endsection
