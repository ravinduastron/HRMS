@extends('layouts.app')
@section('title', 'Contribution rates')

@section('content')
@php
    $canManage = auth()->user()->can('statutory.manage') && auth()->user()->can('schemes.all');
    $cols = ['epf_employee' => 'EPF employee', 'epf_employer' => 'EPF employer', 'msps_employee' => 'MSPS employee', 'msps_employer' => 'MSPS employer', 'etf_employer' => 'ETF employer'];
@endphp
<x-page-header title="Statutory" description="EPF, MSPS and ETF rates, versioned by effective date." />
@include('statutory._tabs')

@unless ($inForce)
    <x-alert type="warning" class="mb-4">No contribution rates are in force yet, so EPF, MSPS and ETF cannot be calculated. @if ($canManage)Add the first version below.@endif</x-alert>
@endunless

<div class="grid gap-6 lg:grid-cols-3">
    <x-card title="Rate versions" :padding="false" class="lg:col-span-2">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Effective from</th>
                    @foreach ($cols as $label)<th class="px-5 py-3 text-right font-medium">{{ $label }}</th>@endforeach
                </tr>
            </x-slot:head>
            @forelse ($versions as $v)
                @php $r = $v->rates(); @endphp
                <tr>
                    <td class="px-5 py-3">
                        <span class="font-medium text-slate-900">{{ $v->effective_from->format('d M Y') }}</span>
                        @if ($inForce && $v->id === $inForce->id)<x-badge color="green" class="ml-1">In force</x-badge>@endif
                        <span class="block text-xs text-slate-500">{{ $v->change_note }} · {{ $v->created_by_name ?? 'System' }}</span>
                    </td>
                    @foreach ($cols as $k => $label)<td class="px-5 py-3 text-right font-mono">{{ $r[$k] }}%</td>@endforeach
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No rates yet.</td></tr>
            @endforelse
        </x-table>
        <div class="space-y-1 border-t border-slate-200 px-5 py-3 text-sm text-slate-500">
            <p>The employee rates are the default for each fund. An individual employee can have their own employee rate under Employee rates. The employer rates and ETF apply to everyone.</p>
            <p>ETF is paid for every employee, MSPS members included. Employees marked exempt on their salary pay none of these.</p>
            <p>Amounts are rounded to {{ config('statutory.rounding_dp') }} decimal places ({{ str_replace('_', ' ', config('statutory.rounding_mode')) }}). This is set in config/statutory.php.</p>
        </div>
    </x-card>

    @if ($canManage)
        <x-card title="New version">
            <form method="POST" action="{{ route('statutory.rates.store') }}" class="space-y-4">
                @csrf
                <x-input name="effective_from" type="date" label="Effective from" :value="old('effective_from', $latest ? $latest->effective_from->copy()->addDay()->toDateString() : today()->toDateString())" required
                    :hint="$latest ? 'Must be after '.$latest->effective_from->format('d M Y').'.' : null" />
                @foreach ($cols as $k => $label)
                    <x-input name="{{ $k }}_rate" label="{{ $label }} (%)" :value="old($k.'_rate', $latest ? $latest->rates()[$k] : '')" inputmode="decimal" required />
                @endforeach
                <x-input name="change_note" label="Reason" :value="old('change_note', $latest ? '' : 'Initial setup')" required />
                <x-button type="submit" class="w-full">Save version</x-button>
            </form>
        </x-card>
    @endif
</div>
@endsection
