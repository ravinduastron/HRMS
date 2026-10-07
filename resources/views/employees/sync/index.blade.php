@extends('layouts.app')
@section('title', 'Sync history')

@section('content')
@php $color = ['queued' => 'slate', 'running' => 'amber', 'completed' => 'green', 'failed' => 'red']; @endphp
@if ($runs->contains(fn ($r) => $r->isRunning()))<div x-data x-init="setTimeout(() => location.reload(), 5000)"></div>@endif

<x-page-header title="MiHCM sync history" description="Every sync, what it changed, and who started it.">
    <x-slot:actions>
        @can('employees.sync')
            <x-confirm-action :action="route('employees.sync.store')" title="Sync employees from MiHCM?" confirm="Start sync" variant="primary">
                <x-slot:trigger><x-button>Run sync now</x-button></x-slot:trigger>
                Fetches all employees from MiHCM and updates this system. Status you set here (Active / Payroll) is never overwritten.
            </x-confirm-action>
        @endcan
    </x-slot:actions>
</x-page-header>

<x-card :padding="false">
    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">Run</th><th class="px-5 py-3 font-medium">Started</th><th class="px-5 py-3 font-medium">By</th>
                <th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3 text-right font-medium">Fetched</th>
                <th class="px-5 py-3 text-right font-medium">New</th><th class="px-5 py-3 text-right font-medium">Updated</th>
                <th class="px-5 py-3 text-right font-medium">Deactivated</th><th class="px-5 py-3 text-right font-medium">Skipped</th><th class="px-5 py-3"></th>
            </tr>
        </x-slot:head>
        @forelse ($runs as $r)
            <tr>
                <td class="px-5 py-3 font-medium text-slate-900">#{{ $r->id }}</td>
                <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ ($r->started_at ?? $r->created_at)->format('d M Y H:i') }}@if ($r->durationSeconds() !== null)<span class="text-xs"> · {{ $r->durationSeconds() }}s</span>@endif</td>
                <td class="px-5 py-3">{{ $r->triggered_by_name ?? ucfirst($r->trigger) }}</td>
                <td class="px-5 py-3"><x-badge :color="$color[$r->status] ?? 'slate'">{{ ucfirst($r->status) }}</x-badge></td>
                <td class="px-5 py-3 text-right">{{ $r->fetched_count }}</td>
                <td class="px-5 py-3 text-right">{{ $r->created_count }}</td>
                <td class="px-5 py-3 text-right">{{ $r->updated_count }}</td>
                <td class="px-5 py-3 text-right">{{ $r->deactivated_count }}</td>
                <td class="px-5 py-3 text-right">{{ $r->skipped_count }}</td>
                <td class="px-5 py-3 text-right"><x-button :href="route('employees.sync.show', $r)" variant="ghost" size="sm">Details</x-button></td>
            </tr>
        @empty
            <tr><td colspan="10" class="px-5 py-10 text-center text-slate-500">No syncs yet.</td></tr>
        @endforelse
    </x-table>
    @if ($runs->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $runs->links() }}</div>@endif
</x-card>
@endsection
