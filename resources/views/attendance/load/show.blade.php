@extends('layouts.app')
@section('title', 'Load #'.$run->id)

@section('content')
@php
    $color = ['queued' => 'slate', 'running' => 'amber', 'completed' => 'green', 'failed' => 'red'];
    $typeColor = ['updated' => 'amber', 'missing' => 'red', 'restored' => 'brand'];
    $scopeLabel = ['attendance' => 'Attendance', 'leave' => 'Leave', 'both' => 'Attendance and leave'];
    $fields = ['fetched' => 'Fetched', 'created' => 'New', 'updated' => 'Updated', 'unchanged' => 'Unchanged', 'restored' => 'Returned again', 'missing' => 'Missing', 'unmatched' => 'No matching employee', 'skipped' => 'Skipped'];
    $quality = collect($run->quality ?? []);
@endphp
@if ($run->isRunning())<div x-data x-init="setTimeout(() => location.reload(), 4000)"></div>@endif

<x-page-header :title="'Load #'.$run->id" :description="($scopeLabel[$run->scope] ?? $run->scope).' · '.$run->from_date->format('d M Y').' to '.$run->to_date->format('d M Y').' · '.($run->triggered_by_name ?? ucfirst($run->trigger))">
    <x-slot:actions><x-badge :color="$color[$run->status] ?? 'slate'">{{ ucfirst($run->status) }}</x-badge><x-button :href="route('attendance.load.index')" variant="secondary">All loads</x-button></x-slot:actions>
</x-page-header>

@if ($run->error_message)<x-alert type="error" title="Load failed" class="mb-4">{{ $run->error_message }}<br><span class="text-xs">Nothing was saved.</span></x-alert>@endif
@if ($run->notes)<x-alert type="warning" title="Notes" class="mb-4">{!! nl2br(e($run->notes)) !!}</x-alert>@endif
@if ($run->status === 'completed')
    <x-alert type="info" class="mb-4">This load does not make the data final. MiHCM may still change it (pending leave, unapproved overtime, adjusted attendance). A pay run keeps its own summary when it calculates.</x-alert>
@endif

<div class="space-y-6">
    @if ($quality->isNotEmpty())
        <x-card title="What needs attention" :padding="false">
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($quality as $q)
                    <li class="flex items-start justify-between gap-4 px-5 py-3">
                        <div>
                            <p class="font-medium text-slate-900">{{ $q['label'] }}</p>
                            @if (! empty($q['sample']))<p class="mt-0.5 text-xs text-slate-500">e.g. {{ implode(', ', $q['sample']) }}@if ($q['count'] > count($q['sample'])) …@endif</p>@endif
                        </div>
                        <x-badge :color="$q['severity'] === 'warning' ? 'amber' : 'green'">{{ $q['count'] }}</x-badge>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    @if ($run->counts)
        <x-card title="Counts" :padding="false">
            <x-table>
                <x-slot:head>
                    <tr><th class="px-5 py-3 font-medium"></th>@foreach ($fields as $label)<th class="px-5 py-3 text-right font-medium">{{ $label }}</th>@endforeach</tr>
                </x-slot:head>
                @foreach ($run->counts as $kind => $c)
                    <tr>
                        <td class="px-5 py-3 font-medium text-slate-900">{{ ucfirst($kind) }}</td>
                        @foreach ($fields as $k => $label)<td class="px-5 py-3 text-right">{{ number_format($c[$k] ?? 0) }}</td>@endforeach
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    @endif

    <x-card title="Changes since the previous load" :padding="false">
        <x-slot:actions>
            <div class="flex flex-wrap gap-1 text-xs">
                <a href="{{ route('attendance.load.show', $run) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => ! $kind && ! $type, 'hover:bg-slate-100' => $kind || $type])>All</a>
                @foreach (['attendance' => 'Attendance', 'leave' => 'Leave'] as $k => $label)
                    <a href="{{ route('attendance.load.show', [$run, 'kind' => $k]) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => $kind === $k, 'hover:bg-slate-100' => $kind !== $k])>{{ $label }}</a>
                @endforeach
                @foreach (['updated', 'missing', 'restored'] as $t)
                    <a href="{{ route('attendance.load.show', array_filter([$run, 'kind' => $kind, 'type' => $t])) }}" @class(['rounded px-2 py-1', 'bg-brand-50 font-medium text-brand-800' => $type === $t, 'hover:bg-slate-100' => $type !== $t])>{{ ucfirst($t) }}</a>
                @endforeach
            </div>
        </x-slot:actions>
        @forelse ($changes as $c)
            <div class="border-b border-slate-100 px-5 py-3 last:border-0">
                <div class="mb-1.5 flex flex-wrap items-center gap-2">
                    <x-badge :color="$typeColor[$c->change_type] ?? 'slate'">{{ $c->change_type }}</x-badge>
                    <span class="text-sm font-medium text-slate-900">{{ $c->employee_no }}</span>
                    <span class="text-xs text-slate-500">{{ ucfirst($c->kind) }} · {{ $c->kind === 'attendance' ? \Illuminate\Support\Carbon::parse($c->ref)->format('d M Y') : 'entry '.$c->ref }}</span>
                </div>
                @include('employees._diff', ['changes' => $c->changes])
            </div>
        @empty
            <p class="px-5 py-8 text-center text-sm text-slate-500">{{ $run->isRunning() ? 'Load in progress…' : 'Nothing changed, went missing or came back in this load.' }}</p>
        @endforelse
        @if ($changes->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $changes->links() }}</div>@endif
    </x-card>
</div>
@endsection
