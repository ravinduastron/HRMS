@extends('layouts.app')
@section('title', $payComponent->code)

@section('content')
@php
$cur = $payComponent->current();
$latest = $payComponent->latestVersion();
$yes = fn ($b) => $b ? 'Yes' : 'No';
@endphp
<x-page-header :title="$payComponent->name" :description="$payComponent->code.' · '.\App\Models\PayComponent::TYPES[$payComponent->type]">
    <x-slot:actions>
        <x-button :href="route('pay-components.test', $payComponent)" variant="secondary">Test panel</x-button>
        @can('pay-components.manage')
        <x-button :href="route('pay-components.versions.create', $payComponent)" variant="secondary">New version</x-button>
        <x-confirm-action :action="route('pay-components.toggle', $payComponent)" method="PATCH" :title="$payComponent->is_active ? 'Deactivate '.$payComponent->code.'?' : 'Activate '.$payComponent->code.'?'"
            :confirm="$payComponent->is_active ? 'Deactivate' : 'Activate'" :variant="$payComponent->is_active ? 'danger' : 'primary'">
            <x-slot:trigger><x-button :variant="$payComponent->is_active ? 'danger' : 'primary'">{{ $payComponent->is_active ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
            {{ $payComponent->is_active ? 'It can no longer be used in new formulas. Past payslips and history are kept.' : 'It can be used in formulas again.' }}
        </x-confirm-action>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card title="Current calculation">
        @if ($cur)
        <dl class="grid gap-x-6 gap-y-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-slate-500">In force since</dt>
                <dd class="mt-0.5 font-medium text-slate-900">{{ $cur->effective_from->format('d M Y') }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Method</dt>
                <dd class="mt-0.5 font-medium text-slate-900">{{ \App\Models\PayComponentVersion::METHODS[$cur->calc_method] }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Rounding</dt>
                <dd class="mt-0.5 font-medium text-slate-900">{{ $cur->rounding_dp }} dp, {{ ['half_up' => 'nearest', 'up' => 'always up', 'down' => 'always down'][$cur->rounding_mode] }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Paid</dt>
                <dd class="mt-0.5 font-medium text-slate-900">{{ $cur->payment_timing === 'separate' ? 'Separately' : 'With salary' }}, {{ str_replace('_', ' ', $cur->pay_cycle_rule) }}</dd>
            </div>
            <div class="sm:col-span-2 lg:col-span-4">
                <dt class="text-slate-500">{{ $cur->displayFormula() ? 'Formula' : 'Amount' }}</dt>
                <dd class="mt-0.5 font-medium text-slate-900">
                    @if ($cur->displayFormula())
                        <code class="break-all rounded bg-slate-100 px-2 py-1 text-xs">{{ $cur->displayFormula() }}</code>
                    @elseif ($cur->calc_method === 'fixed')
                        {{ $cur->default_amount !== null 
                            ? 'Default '.number_format($cur->default_amount, 2) 
                            : 'Set per employee' }}
                    @else
                        {{ $cur->calc_method === 'input' 
                            ? 'Entered each pay period' 
                            : 'Supplied by another module' }}
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-slate-500">EPF / ETF / Tax</dt>
                <dd class="mt-0.5 font-medium text-slate-900">{{ $yes($cur->epf_applicable) }} / {{ $yes($cur->etf_applicable) }} / {{ $yes($cur->tax_applicable) }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Proration</dt>
                <dd class="mt-0.5 font-medium text-slate-900">{{ str_replace('_', ' ', ucfirst($cur->proration)) }}</dd>
            </div>
        </dl>
        @else
        <p class="text-sm text-slate-500">No version is in force yet
            @if ($latest)
                : the first one starts on {{ $latest->effective_from->format('d M Y') }}
            @endif.</p>
        @endif
        @if ($latest && $cur && $latest->id !== $cur->id)
        <x-alert type="info" class="mt-4">A newer version starts on {{ $latest->effective_from->format('d M Y') }}.</x-alert>
        @endif
    </x-card>

    @can('pay-components.manage')
    <x-card title="Details">
        <form method="POST" action="{{ route('pay-components.update', $payComponent) }}" class="grid gap-5 sm:grid-cols-2">
            @csrf @method('PATCH')
            <x-input name="name" label="Name" :value="old('name', $payComponent->name)" required />
            <x-input name="payslip_order" type="number" label="Payslip order" :value="old('payslip_order', $payComponent->payslip_order)" required />
            <div class="sm:col-span-2"><x-input name="description" label="Description" :value="old('description', $payComponent->description)" /></div>
            <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="show_on_payslip" value="1" class="size-4 accent-brand-700" @checked($payComponent->show_on_payslip)> Show on the payslip</label>
            <div class="flex justify-end"><x-button type="submit">Save details</x-button></div>
        </form>
    </x-card>
    @endcan

    <x-card title="Version history" :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Effective from</th>
                    <th class="px-5 py-3 font-medium">Calculation</th>
                    <th class="px-5 py-3 font-medium">Changed by</th>
                    <th class="px-5 py-3 font-medium">Reason</th>
                </tr>
            </x-slot:head>
            @foreach ($payComponent->versions as $v)
            <tr>
                <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ $v->effective_from->format('d M Y') }}
                    @if ($cur && $v->id === $cur->id)<x-badge color="green" class="ml-1">In force</x-badge>@endif</td>
                <td class="px-5 py-3 text-xs text-slate-600">{{ explode(' ', \App\Models\PayComponentVersion::METHODS[$v->calc_method])[0] }}
                    @if ($v->displayFormula())<code class="mt-0.5 block break-all">{{ $v->displayFormula() }}</code>@elseif ($v->default_amount !== null)<span class="block">default {{ number_format($v->default_amount, 2) }}</span>@endif</td>
                <td class="px-5 py-3 text-slate-500">{{ $v->created_by_name ?? 'System' }}<span class="block text-xs">{{ $v->created_at->format('d M Y H:i') }}</span></td>
                <td class="px-5 py-3 text-slate-500">{{ $v->change_note ?? '—' }}</td>
            </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection