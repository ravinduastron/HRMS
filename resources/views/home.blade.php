@extends('layouts.app')

@section('content')
<x-page-header :title="'Welcome back, '.explode(' ', auth()->user()->name)[0]" description="Here is where the September pay run stands.">
    <x-slot:actions>
        <x-button variant="secondary">Export summary</x-button>
        <x-button @click="$dispatch('open-modal', 'start-run')"><x-icon name="plus" class="size-4" /> New pay run</x-button>
    </x-slot:actions>
</x-page-header>

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($stats as $s)
        <x-stat-card :label="$s['label']" :value="$s['value']" :delta="$s['delta']" :trend="$s['trend']" :icon="$s['icon']" />
    @endforeach
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    <x-card title="September pay run" class="lg:col-span-2">
        <x-slot:actions><x-badge color="amber">In review</x-badge></x-slot:actions>
        <ol class="space-y-4">
            @foreach ($steps as $step)
                <li class="flex items-center gap-3 text-sm">
                    <span @class([
                        'grid size-6 shrink-0 place-items-center rounded-full text-xs font-medium',
                        'bg-brand-700 text-white' => $step['state'] === 'done',
                        'bg-accent-400/20 text-accent-500 ring-2 ring-accent-400' => $step['state'] === 'current',
                        'bg-slate-100 text-slate-400' => $step['state'] === 'todo',
                    ])>
                        @if ($step['state'] === 'done')<x-icon name="check" class="size-3.5" />@else{{ $loop->iteration }}@endif
                    </span>
                    <span @class(['text-slate-900 font-medium' => $step['state'] === 'current', 'text-slate-500' => $step['state'] === 'todo'])>{{ $step['label'] }}</span>
                </li>
            @endforeach
        </ol>
        <div class="mt-6 flex gap-2">
            <x-button href="{{ url('/payroll/current') }}">Continue review</x-button>
            <x-button variant="ghost">View variances</x-button>
        </div>
    </x-card>

    <x-card title="Needs your attention">
        <x-alert type="warning" title="Overtime cut-off in 2 days">37 hours are still unapproved.</x-alert>
        <ul class="mt-4 divide-y divide-slate-100 text-sm">
            <li class="flex justify-between py-2.5"><span>Leave requests</span><span class="font-medium">12</span></li>
            <li class="flex justify-between py-2.5"><span>Loan requests</span><span class="font-medium">4</span></li>
            <li class="flex justify-between py-2.5"><span>New joiners to confirm</span><span class="font-medium">3</span></li>
        </ul>
    </x-card>
</div>

<x-card title="Recent activity" :padding="false" class="mt-6">
    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">Who</th>
                <th class="px-5 py-3 font-medium">What happened</th>
                <th class="px-5 py-3 font-medium">When</th>
                <th class="px-5 py-3 font-medium">Status</th>
            </tr>
        </x-slot:head>
        @foreach ($activity as $row)
            <tr>
                <td class="px-5 py-3 font-medium text-slate-900">{{ $row['who'] }}</td>
                <td class="px-5 py-3">{{ $row['what'] }}</td>
                <td class="px-5 py-3 text-slate-500">{{ $row['when'] }}</td>
                <td class="px-5 py-3">
                    <x-badge :color="['Approved' => 'green', 'Completed' => 'brand', 'Pending' => 'amber', 'Rejected' => 'red'][$row['status']]">{{ $row['status'] }}</x-badge>
                </td>
            </tr>
        @endforeach
    </x-table>
</x-card>

<x-modal name="start-run" title="Start a new pay run">
    This creates a draft run for the next pay period using the current attendance and adjustments.
    <x-slot:footer>
        <x-button variant="secondary" @click="$dispatch('close-modal')">Cancel</x-button>
        <x-button>Create draft run</x-button>
    </x-slot:footer>
</x-modal>
@endsection
