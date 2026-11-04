@extends('layouts.app')
@section('title', 'Hire purchase deductions')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
    $q = array_filter(['month' => $month->format('Y-m'), 'scheme' => $scheme, 'by' => $by === 'hp' ? null : $by]);
    $views = ['hp' => 'By hire purchase', 'vendor' => 'By vendor', 'component' => 'By pay item'];
@endphp
<x-page-header title="Monthly hire purchase deductions" description="What hire purchases take from pay in a month. By vendor shows what the company pays each vendor at month end.">
    <x-slot:actions>
        <x-button :href="route('hire-purchases.deductions.export', $q)" variant="secondary">Export CSV</x-button>
        @can('hire-purchases.manage')
            @if (count($rows))
                <x-confirm-action :action="route('hire-purchases.deductions.record')" :title="'Record '.$month->format('F Y').' as deducted?'" confirm="Record as deducted" variant="primary" phrase="RECORD">
                    <x-slot:trigger><x-button>Record as deducted</x-button></x-slot:trigger>
                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}"><input type="hidden" name="scheme" value="{{ $scheme }}">
                    Every installment due in {{ $month->format('F Y') }} below is marked as deducted. A pay run will do this itself when it locks; use this only while payroll is not run here. Installments of earlier months are not touched.
                </x-confirm-action>
            @endif
        @endcan
    </x-slot:actions>
</x-page-header>

<x-card :padding="false">
    <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
        <input type="hidden" name="by" value="{{ $by === 'hp' ? '' : $by }}">
        <label class="text-sm font-medium text-slate-700">Month <input type="month" name="month" value="{{ $month->format('Y-m') }}" class="{{ $field }} mt-1.5 w-48"></label>
        <div class="w-56"><x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="$scheme" empty="All schemes" /></div>
        <x-button type="submit" variant="secondary">Show</x-button>
    </form>

    <nav class="flex gap-1 border-b border-slate-200 px-4 pt-3" aria-label="Deduction views">
        @foreach ($views as $k => $label)
            <a href="{{ route('hire-purchases.deductions', array_filter(['month' => $month->format('Y-m'), 'scheme' => $scheme, 'by' => $k === 'hp' ? null : $k])) }}" @class([
                '-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                'border-brand-700 text-brand-800' => $by === $k,
                'border-transparent text-slate-500 hover:text-slate-800' => $by !== $k,
            ])>{{ $label }}</a>
        @endforeach
    </nav>

    @if ($overdue)<div class="border-b border-slate-200 p-4"><x-alert type="warning">{{ $overdue }} installment(s) are from earlier months and were never deducted. They are marked "Overdue". Payroll must decide how to recover them.</x-alert></div>@endif
    @if ($left)<div class="border-b border-slate-200 p-4"><x-alert type="warning">{{ $left }} installment(s) belong to employees who have left or are inactive. They are marked "Employee has left" and are NOT stopped automatically: open the hire purchase and stop the deductions if they should not be recovered.</x-alert></div>@endif

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
                <tr><td colspan="{{ count($matrix['columns']) + 2 }}" class="px-5 py-10 text-center text-slate-500">No hire purchase installments are due.</td></tr>
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
                <tr><th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Hire purchase</th><th class="px-5 py-3 font-medium">Installment</th>
                    <th class="px-5 py-3 text-right font-medium">Amount</th></tr>
            </x-slot:head>
            @php $groups = $by === 'vendor' ? $vendors : [['code' => null, 'name' => null, 'rows' => $rows, 'total' => null, 'employee_count' => 0]]; @endphp
            @foreach ($groups as $g)
                @if ($by === 'vendor')
                    <tr class="bg-slate-50"><td colspan="4" class="px-5 py-2.5 text-sm font-semibold text-slate-900">{{ $g['name'] }} <span class="font-mono text-xs font-normal text-slate-500">{{ $g['code'] }}</span></td></tr>
                @endif
                @foreach ($g['rows'] as $r)
                    @php $l = $r['line']; @endphp
                    <tr>
                        <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $r['employee']->display_name ?? $r['employee']->full_name }}</span><p class="text-xs text-slate-500">{{ $r['employee']->employee_no }}</p>
                            @if (\App\Services\HirePurchaseService::hasLeft($r['employee']))<x-badge color="amber">Employee has left</x-badge>@endif</td>
                        <td class="px-5 py-3"><a href="{{ route('hire-purchases.show', $l['hire_purchase_id']) }}" class="hover:underline">{{ $l['reference'] }}</a><p class="text-xs text-slate-500">{{ $by === 'vendor' ? $l['item'] : $l['vendor_name'].' · '.$l['item'] }}</p></td>
                        <td class="px-5 py-3">#{{ $l['seq'] }} · {{ \Illuminate\Support\Carbon::parse($l['due_month'])->format('M Y') }}@if ($l['overdue'])<x-badge color="amber" class="ml-1">Overdue</x-badge>@endif</td>
                        <td class="px-5 py-3 text-right font-mono font-medium">{{ $m($l['amount']) }}</td>
                    </tr>
                @endforeach
                @if ($by === 'vendor')
                    <tr class="font-medium text-slate-900">
                        <td class="px-5 py-3 text-right" colspan="3">Total for {{ $g['name'] }} ({{ $g['employee_count'] }} {{ \Illuminate\Support\Str::plural('employee', $g['employee_count']) }})</td>
                        <td class="px-5 py-3 text-right font-mono">{{ $m($g['total']) }}</td>
                    </tr>
                @endif
            @endforeach
            @if (! count($rows))
                <tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">No hire purchase installments are due.</td></tr>
            @else
                <tr class="bg-slate-50 font-medium text-slate-900">
                    <td class="px-5 py-3" colspan="3">Grand total</td>
                    <td class="px-5 py-3 text-right font-mono">{{ $m($total) }}</td>
                </tr>
            @endif
        </x-table>
    @endif
</x-card>
@endsection
