@extends('layouts.app')
@section('title', 'Test '.$payComponent->code)

@section('content')
@php $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm font-mono focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600'; @endphp
<x-page-header :title="'Test panel: '.$payComponent->code" description="Try the calculation with sample numbers. Nothing is saved and no real employee data is used.">
    <x-slot:actions><x-button :href="route('pay-components.show', $payComponent)" variant="secondary">Back to component</x-button></x-slot:actions>
</x-page-header>

<div class="grid gap-6 lg:grid-cols-3">
    <x-card title="Sample values">
        <form method="POST" action="{{ route('pay-components.test', $payComponent) }}" class="space-y-4">
            @csrf
            <x-input name="date" type="date" label="Calculate as at" :value="$date->toDateString()" hint="Uses the version of every component in force on this date." />

            @if ($needs['variables'])
                <div>
                    <p class="mb-2 text-xs font-medium text-slate-500">System variables</p>
                    <div class="space-y-3">
                        @foreach ($needs['variables'] as $name)
                            <div><label for="v_{{ $name }}" class="block text-xs text-slate-600"><span class="font-mono">{{ $name }}</span> · {{ $sysLabels[$name][0] ?? 'Unknown variable' }}</label>
                                <input id="v_{{ $name }}" name="values[{{ $name }}]" value="{{ $values[$name] ?? '0' }}" inputmode="decimal" class="{{ $field }} mt-1"></div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($needs['leaves'])
                <div>
                    <p class="mb-2 text-xs font-medium text-slate-500">Amounts the calculation starts from</p>
                    <div class="space-y-3">
                        @foreach ($needs['leaves'] as $code)
                            <div><label for="c_{{ $code }}" class="block text-xs text-slate-600"><span class="font-mono">{{ $code }}</span> · {{ $defs[$code]['name'] }}
                                ({{ ['fixed' => 'fixed', 'input' => 'entered each period', 'system' => 'from another module'][$defs[$code]['method']] }})</label>
                                <input id="c_{{ $code }}" name="values[{{ $code }}]" value="{{ $values[$code] ?? '0' }}" inputmode="decimal" class="{{ $field }} mt-1"></div>
                        @endforeach
                    </div>
                </div>
            @endif

            <x-button type="submit" class="w-full">Calculate</x-button>
        </form>
    </x-card>

    <div class="space-y-4 lg:col-span-2">
        @if ($error)<x-alert type="error" title="Could not calculate">{{ $error }}</x-alert>@endif

        @if ($results)
            <x-card title="Result" :padding="false">
                <div class="border-b border-slate-100 px-5 py-4">
                    <p class="text-xs text-slate-500">{{ $payComponent->name }}</p>
                    <p class="mt-1 text-3xl font-semibold tracking-tight text-slate-900">{{ number_format((float) $results['results'][$payComponent->code]['value'], max(0, (int) ($defs[$payComponent->code]['rounding_dp'] ?? 2))) }}</p>
                </div>
                <x-table>
                    <x-slot:head>
                        <tr><th class="px-5 py-3 font-medium">Step</th><th class="px-5 py-3 font-medium">Component</th><th class="px-5 py-3 font-medium">How</th>
                            <th class="px-5 py-3 text-right font-medium">Before rounding</th><th class="px-5 py-3 text-right font-medium">Result</th></tr>
                    </x-slot:head>
                    @foreach ($results['order'] as $i => $code)
                        @php $r = $results['results'][$code]; @endphp
                        <tr @class(['bg-brand-50/50' => $code === $payComponent->code])>
                            <td class="px-5 py-3 text-slate-400">{{ $i + 1 }}</td>
                            <td class="px-5 py-3"><span class="font-mono text-xs font-medium text-slate-900">{{ $code }}</span><span class="block text-xs text-slate-500">{{ $r['name'] }}</span></td>
                            <td class="px-5 py-3 text-xs text-slate-600">@if ($r['formula'])<code class="break-all">{{ $r['formula'] }}</code>@else{{ ['fixed' => 'Fixed amount', 'input' => 'Entered amount', 'system' => 'From another module'][$r['method']] }}@endif</td>
                            <td class="px-5 py-3 text-right font-mono text-xs text-slate-500">{{ $r['raw'] }}</td>
                            <td class="px-5 py-3 text-right font-mono font-medium text-slate-900">{{ $r['value'] }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>
        @elseif (! $error)
            <x-card><p class="text-sm text-slate-500">Adjust the sample values on the left and press Calculate.</p></x-card>
        @endif
    </div>
</div>
@endsection
