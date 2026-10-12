@extends('layouts.app')
@section('title', 'Pay components')

@section('content')
@php
    $field = 'rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:outline-brand-600';
    $typeColor = ['earning' => 'green', 'deduction' => 'red', 'employer_contribution' => 'brand', 'info' => 'slate'];
@endphp

<x-page-header title="Pay components" description="Earnings, deductions and contributions, and how each is calculated.">
    @can('pay-components.manage')
        <x-slot:actions>
            <x-button :href="route('pay-components.create')">
                <x-icon name="plus" class="size-4" /> New component
            </x-button>
        </x-slot:actions>
    @endcan
</x-page-header>

<x-card :padding="false">
    <form method="GET" class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
        <select name="type" class="{{ $field }}">
            <option value="">All types</option>
            @foreach (\App\Models\PayComponent::TYPES as $k => $t)
                <option value="{{ $k }}" @selected(request('type') === $k)>{{ $t }}</option>
            @endforeach
        </select>

        <select name="status" class="{{ $field }}">
            <option value="">Any status</option>
            <option value="active" @selected(request('status') === 'active')>Active</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
        </select>

        <x-button type="submit" variant="secondary">Filter</x-button>
    </form>

    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">Code</th>
                <th class="px-5 py-3 font-medium">Name</th>
                <th class="px-5 py-3 font-medium">Type</th>
                <th class="px-5 py-3 font-medium">Calculation</th>
                <th class="px-5 py-3 font-medium">Rules</th>
                <th class="px-5 py-3 font-medium">Status</th>
                <th class="px-5 py-3"></th>
            </tr>
        </x-slot:head>

        @forelse ($components as $c)
            @php $v = $c->current() ?? $c->latestVersion(); @endphp
            <tr>
                <td class="px-5 py-3 font-mono text-xs font-medium text-slate-900">
                    <a href="{{ route('pay-components.show', $c) }}" class="hover:underline">{{ $c->code }}</a>
                </td>
                <td class="px-5 py-3">{{ $c->name }}</td>
                <td class="px-5 py-3">
                    <x-badge :color="$typeColor[$c->type]">{{ \App\Models\PayComponent::TYPES[$c->type] }}</x-badge>
                </td>
                <td class="max-w-xs px-5 py-3 text-xs text-slate-600">
                    @if ($v)
                        <span class="font-medium text-slate-800">
                            {{ explode(' ', \App\Models\PayComponentVersion::METHODS[$v->calc_method])[0] }}
                        </span>

                        @if ($v->displayFormula())
                            <code class="mt-0.5 block truncate" title="{{ $v->displayFormula() }}">{{ $v->displayFormula() }}</code>
                        @elseif ($v->calc_method === 'fixed' && $v->default_amount !== null)
                            <span class="block">default {{ number_format($v->default_amount, 2) }}</span>
                        @endif
                    @else
                        —
                    @endif
                </td>
                <td class="px-5 py-3">
                    @if ($v)
                        <div class="flex flex-wrap gap-1">
                            @foreach (['epf_applicable' => 'EPF', 'etf_applicable' => 'ETF', 'tax_applicable' => 'Tax'] as $k => $t)
                                @if ($v->$k)
                                    <x-badge color="slate">{{ $t }}</x-badge>
                                @endif
                            @endforeach

                            @if ($v->payment_timing === 'separate')
                                <x-badge color="amber">Separate</x-badge>
                            @endif
                        </div>
                    @endif
                </td>
                <td class="px-5 py-3">
                    <x-badge :color="$c->is_active ? 'green' : 'slate'">{{ $c->is_active ? 'Active' : 'Inactive' }}</x-badge>
                </td>
                <td class="px-5 py-3 text-right">
                    <x-button :href="route('pay-components.show', $c)" variant="ghost" size="sm">Open</x-button>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="px-5 py-10 text-center text-slate-500">No pay components yet.</td>
            </tr>
        @endforelse
    </x-table>
</x-card>
@endsection