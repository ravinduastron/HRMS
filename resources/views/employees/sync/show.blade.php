@extends('layouts.app')
@section('title', 'Sync #'.$run->id)

@section('content')
@php
    $color = ['queued' => 'slate', 'running' => 'amber', 'completed' => 'green', 'failed' => 'red'];
    $typeColor = ['created' => 'green', 'imported' => 'slate', 'updated' => 'amber', 'deactivated' => 'red', 'reactivated' => 'brand', 'skipped' => 'slate'];
@endphp
@if ($run->isRunning())<div x-data x-init="setTimeout(() => location.reload(), 4000)"></div>@endif

<x-page-header :title="'Sync #'.$run->id" :description="($run->started_at ?? $run->created_at)->format('d M Y H:i').' · '.($run->triggered_by_name ?? ucfirst($run->trigger))">
    <x-slot:actions><x-badge :color="$color[$run->status] ?? 'slate'">{{ ucfirst($run->status) }}</x-badge><x-button :href="route('employees.sync.index')" variant="secondary">All syncs</x-button></x-slot:actions>
</x-page-header>

@if ($run->error_message)<x-alert type="error" title="Sync failed" class="mb-4">{{ $run->error_message }}<br><span class="text-xs">No employee data was changed.</span></x-alert>@endif
@if ($run->notes)<x-alert type="warning" title="Needs attention" class="mb-4">{!! nl2br(e($run->notes)) !!}</x-alert>@endif
@if ($run->is_baseline)<x-alert type="info" class="mb-4">This was the first load, so employees are recorded as "imported" rather than "new".</x-alert>@endif

<div class="mb-6 grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
    @foreach (['Fetched' => $run->fetched_count, 'New' => $run->created_count, 'Updated' => $run->updated_count, 'Deactivated' => $run->deactivated_count, 'Reactivated' => $run->reactivated_count, 'Unchanged' => $run->unchanged_count] as $label => $n)
        <x-stat-card :label="$label" :value="number_format($n)" />
    @endforeach
</div>

<x-card title="Changes" :padding="false">
    <x-slot:actions>
        <div class="flex flex-wrap gap-1 text-xs">
            <a href="{{ route('employees.sync.show', $run) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => ! $type, 'hover:bg-slate-100' => $type])>All</a>
            @foreach ($types as $t => $n)
                <a href="{{ route('employees.sync.show', [$run, 'type' => $t]) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => $type === $t, 'hover:bg-slate-100' => $type !== $t])>{{ ucfirst($t) }} ({{ $n }})</a>
            @endforeach
        </div>
    </x-slot:actions>
    @forelse ($changes as $c)
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-3 last:border-0">
            <div class="min-w-0">
                <div class="mb-1.5 flex items-center gap-2">
                    <x-badge :color="$typeColor[$c->change_type] ?? 'slate'">{{ $c->change_type }}</x-badge>
                    @if ($c->employee_id)
                        <a href="{{ route('employees.show', $c->employee_id) }}" class="text-sm font-medium text-slate-900 hover:underline">{{ $c->employee_name ?? $c->employee_no }}</a>
                    @else
                        <span class="text-sm font-medium text-slate-900">{{ $c->employee_name ?? $c->employee_no ?? 'Unknown record' }}</span>
                    @endif
                    <span class="text-xs text-slate-500">{{ $c->employee_no }}</span>
                </div>
                @include('employees._diff', ['changes' => $c->changes])
            </div>
        </div>
    @empty
        <p class="px-5 py-8 text-center text-sm text-slate-500">{{ $run->isRunning() ? 'Sync in progress…' : 'No changes in this run.' }}</p>
    @endforelse
    @if ($changes->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $changes->links() }}</div>@endif
</x-card>
@endsection
