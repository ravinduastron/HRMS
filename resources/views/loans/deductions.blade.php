@extends('layouts.app')
@section('title', 'Loan deductions')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
    $q = array_filter(['month' => $month->format('Y-m'), 'scheme' => $scheme, 'by' => $by === 'loan' ? null : $by]);
    $views = ['loan' => 'By loan', 'component' => 'By pay item', 'group' => 'By group'];
@endphp
<x-page-header title="Monthly loan deductions" description="What loans take from pay in a month. By pay item and by group show what the paysheet shows component-wise and group-wise.">
    <x-slot:actions>
        <x-button :href="route('loans.deductions.export', $q)" variant="secondary">Export CSV</x-button>
        @can('loans.manage')
            @if (count($rows))
                <x-confirm-action :action="route('loans.deductions.record')" :title="'Record '.$month->format('F Y').' as deducted?'" confirm="Record as deducted" variant="primary" phrase="RECORD">
                    <x-slot:trigger><x-button>Record as deducted</x-button></x-slot:trigger>
                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}"><input type="hidden" name="scheme" value="{{ $scheme }}">
                    Every installment due in {{ $month->format('F Y') }} below is marked as deducted and the loan balances go down. A pay run will do this itself when it locks; use this only while payroll is not run here. Installments of earlier months are not touched.
                </x-confirm-action>
            @endif
        @endcan
    </x-slot:actions>
</x-page-header>

<x-card :padding="false">
    <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
        <input type="hidden" name="by" value="{{ $by === 'loan' ? '' : $by }}">
        <label class="text-sm font-medium text-slate-700">Month <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="{{ $field }} mt-1.5 w-48"></label>
        <div class="w-56"><x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="$scheme" empty="All schemes" /></div>
        <x-button type="submit" variant="secondary">Show</x-button>
    </form>

    <nav class="flex gap-1 border-b border-slate-200 px-4 pt-3" aria-label="Deduction views">
        @foreach ($views as $k => $label)
            <a href="{{ route('loans.deductions', array_filter(['month' => $month->format('Y-m'), 'scheme' => $scheme, 'by' => $k === 'loan' ? null : $k])) }}" @class([
                '-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                'border-brand-700 text-brand-800' => $by === $k,
                'border-transparent text-slate-500 hover:text-slate-800' => $by !== $k,
            ])>{{ $label }}</a>
        @endforeach
    </nav>

    @if ($overdue)<div class="border-b border-slate-200 p-4"><x-alert type="warning">{{ $overdue }} installment(s) are from earlier months and were never deducted. They are marked "Overdue" in the By loan view. Payroll must decide how to recover them.</x-alert></div>@endif
    @if ($missingComponents)<div class="border-b border-slate-200 p-4"><x-alert type="error">{{ $missingComponents }} line(s) belong to a loan type with no capital or interest pay item set. Edit the loan type.</x-alert></div>@endif

    @if ($matrix)
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Employee</th>
                    @foreach ($matrix['columns'] as $c)
                        <th class="px-5 py-3 text-right font-medium">{{ $c['label'] }}@if ($c['sub'])<span class="block text-xs font-normal text-slate-400">{{ $c['sub'] }}</span>@endif</th>
                    @endforeach
                    <th class="px-5 py-3 text-right font-medium">Total</th>
                </tr>
            </x-slot:head>
            @forelse ($matrix['rows'] as $r)
                <tr>
                    <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $r['employee']->display_name ?? $r['employee']->full_name }}</span><p class="text-xs text-slate-500">{{ $r['employee']->employee_no }}</p></td>
                    @foreach ($matrix['columns'] as $k => $c)
                        <td class="px-5 py-3 text-right font-mono">{{ isset($r['amounts'][$k]) ? $m($r['amounts'][$k]) : '—' }}</td>
                    @endforeach
                    <td class="px-5 py-3 text-right font-mono font-medium">{{ $m($r['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ count($matrix['columns']) + 2 }}" class="px-5 py-10 text-center text-slate-500">No loan installments are due.</td></tr>
            @endforelse
            @if (count($matrix['rows']))
                <tr class="bg-slate-50 font-medium text-slate-900">
                    <td class="px-5 py-3">Total</td>
                    @foreach ($matrix['columns'] as $k => $c)<td class="px-5 py-3 text-right font-mono">{{ $m($matrix['totals'][$k] ?? '0') }}</td>@endforeach
                    <td class="px-5 py-3 text-right font-mono">{{ $m($matrix['grand']) }}</td>
                </tr>
            @endif
        </x-table>
    @else
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Loan</th><th class="px-5 py-3 font-medium">Installment</th>
                    <th class="px-5 py-3 text-right font-medium">Capital</th><th class="px-5 py-3 text-right font-medium">Interest</th><th class="px-5 py-3 text-right font-medium">Total</th></tr>
            </x-slot:head>
            @forelse ($rows as $r)
                @php $l = $r['line']; @endphp
                <tr>
                    <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $r['employee']->display_name ?? $r['employee']->full_name }}</span><p class="text-xs text-slate-500">{{ $r['employee']->employee_no }}</p></td>
                    <td class="px-5 py-3"><a href="{{ route('loans.show', $l['loan_id']) }}" class="hover:underline">{{ $l['reference'] }}</a><p class="text-xs text-slate-500">{{ $l['type'] }}</p></td>
                    <td class="px-5 py-3">#{{ $l['seq'] }} · {{ \Illuminate\Support\Carbon::parse($l['due_month'])->format('M Y') }}@if ($l['overdue'])<x-badge color="amber" class="ml-1">Overdue</x-badge>@endif</td>
                    <td class="px-5 py-3 text-right font-mono">{{ $m($l['capital']) }}</td>
                    <td class="px-5 py-3 text-right font-mono">{{ $m($l['interest']) }}</td>
                    <td class="px-5 py-3 text-right font-mono font-medium">{{ $m(\App\Payroll\Decimal::add($l['capital'], $l['interest'])) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No loan installments are due.</td></tr>
            @endforelse
            @if (count($rows))
                <tr class="bg-slate-50 font-medium text-slate-900">
                    <td class="px-5 py-3" colspan="3">Total</td>
                    <td class="px-5 py-3 text-right font-mono">{{ $m($totals['capital']) }}</td><td class="px-5 py-3 text-right font-mono">{{ $m($totals['interest']) }}</td><td class="px-5 py-3 text-right font-mono">{{ $m($totals['total']) }}</td>
                </tr>
            @endif
        </x-table>
    @endif
</x-card>
@endsection
