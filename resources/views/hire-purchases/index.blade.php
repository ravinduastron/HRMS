@extends('layouts.app')
@section('title', 'Hire purchases')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
    $color = ['active' => 'green', 'completed' => 'slate', 'stopped' => 'red'];
@endphp
<x-page-header title="Hire purchases" description="Items employees bought from vendors, paid back in monthly installments deducted from salary.">
    <x-slot:actions>
        <x-button :href="route('hire-purchase-vendors.index')" variant="secondary">Vendors</x-button>
        @can('hire-purchases.manage')<x-button :href="route('hire-purchases.create')"><x-icon name="plus" class="size-4" /> New hire purchase</x-button>@endcan
    </x-slot:actions>
</x-page-header>

<x-card :padding="false">
    <form method="GET" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-5">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, employee no, reference, item" class="{{ $field }} lg:col-span-2">
        <x-combobox name="vendor" :options="$vendors->map(fn ($v) => ['value' => $v->id, 'label' => $v->name])->values()" :value="request('vendor')" empty="All vendors" />
        <x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="request('scheme')" empty="All schemes" />
        <div class="flex gap-2">
            <select name="status" class="{{ $field }}"><option value="">Any status</option>
                @foreach (['active' => 'Active', 'completed' => 'Completed', 'stopped' => 'Stopped'] as $k => $t)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $t }}</option>@endforeach</select>
            <x-button type="submit" variant="secondary">Filter</x-button>
        </div>
    </form>

    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Hire purchase</th><th class="px-5 py-3 font-medium">Vendor</th>
                <th class="px-5 py-3 text-right font-medium">Next installment</th><th class="px-5 py-3 text-right font-medium">Left to deduct</th>
                <th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th>
            </tr>
        </x-slot:head>
        @forelse ($purchases as $p)
            @php $s = $summary[$p->id]; $left = $p->status === 'active' && \App\Services\HirePurchaseService::hasLeft($p->employee); @endphp
            <tr>
                <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $p->employee->display_name ?? $p->employee->full_name }}</span><p class="text-xs text-slate-500">{{ $p->employee->employee_no }}</p></td>
                <td class="px-5 py-3"><a href="{{ route('hire-purchases.show', $p) }}" class="font-medium text-slate-900 hover:underline">{{ $p->reference }}</a><p class="text-xs text-slate-500">{{ $p->item_description }}</p></td>
                <td class="px-5 py-3">{{ $p->vendor->name }}</td>
                <td class="px-5 py-3 text-right">@if ($s['next'])<span class="font-mono">{{ $m($s['next']->amount) }}</span><p class="text-xs text-slate-500">{{ $s['next']->due_month->format('M Y') }}</p>@else<span class="text-slate-400">—</span>@endif</td>
                <td class="px-5 py-3 text-right">@if ($p->status === 'active')<span class="font-mono">{{ $m($s['total']) }}</span><p class="text-xs text-slate-500">{{ $s['count'] }} {{ \Illuminate\Support\Str::plural('installment', $s['count']) }}</p>@else<span class="text-slate-400">—</span>@endif</td>
                <td class="px-5 py-3"><x-badge :color="$color[$p->status] ?? 'slate'">{{ ucfirst($p->status) }}</x-badge>@if ($left)<x-badge color="amber" class="ml-1">Employee has left</x-badge>@endif</td>
                <td class="px-5 py-3 text-right"><x-button :href="route('hire-purchases.show', $p)" variant="ghost" size="sm">Open</x-button></td>
            </tr>
        @empty
            <tr><td colspan="7" class="px-5 py-10 text-center text-slate-500">No hire purchases found.</td></tr>
        @endforelse
    </x-table>
    @if ($purchases->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $purchases->links() }}</div>@endif
</x-card>
@endsection
