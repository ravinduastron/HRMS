@extends('layouts.app')
@section('title', 'Audit log')

@section('content')
@php
    $eventColor = fn ($e) => match (true) {
        str_ends_with($e, 'created') => 'green',
        str_ends_with($e, 'deleted') => 'red',
        str_ends_with($e, 'updated') => 'amber',
        str_contains($e, 'failed') || str_contains($e, 'locked') || str_contains($e, 'blocked') => 'red',
        default => 'brand',
    };
    $field = 'block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
@endphp
<x-page-header title="Audit log" description="A permanent record of who did what, and when.">
    @can('audit.export')
        <x-slot:actions><x-button :href="route('audit.export', request()->query())" variant="secondary">Export CSV</x-button></x-slot:actions>
    @endcan
</x-page-header>

<x-card :padding="false">
    <form method="GET" class="grid gap-3 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-4">
        <input type="date" name="from" value="{{ request('from') }}" class="{{ $field }}" aria-label="From date">
        <input type="date" name="to" value="{{ request('to') }}" class="{{ $field }}" aria-label="To date">
        <input type="search" name="user" value="{{ request('user') }}" placeholder="User name" class="{{ $field }}">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Record ID, name or description" class="{{ $field }}">
        <select name="event" class="{{ $field }}"><option value="">All events</option>
            @foreach ($events as $e)<option value="{{ $e }}" @selected(request('event') === $e)>{{ $e }}</option>@endforeach</select>
        <select name="model" class="{{ $field }}"><option value="">All record types</option>
            @foreach ($models as $m)<option value="{{ $m }}" @selected(request('model') === $m)>{{ class_basename($m) }}</option>@endforeach</select>
        <div class="flex items-center gap-3">
            <x-button type="submit" variant="secondary">Filter</x-button>
            @if (request()->hasAny(['from','to','user','q','event','model']))<a href="{{ route('audit.index') }}" class="text-sm text-slate-500 hover:underline">Clear</a>@endif
        </div>
    </form>

    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">When</th><th class="px-5 py-3 font-medium">User</th>
                <th class="px-5 py-3 font-medium">Event</th><th class="px-5 py-3 font-medium">Record</th>
                <th class="px-5 py-3 font-medium">IP</th><th class="px-5 py-3"></th>
            </tr>
        </x-slot:head>
        @forelse ($logs as $l)
            <tr>
                <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ $l->occurred_at->format('d M Y H:i:s') }}</td>
                <td class="px-5 py-3">{{ $l->user_name ?? 'System / anonymous' }}</td>
                <td class="px-5 py-3"><x-badge :color="$eventColor($l->event)">{{ $l->event }}</x-badge></td>
                <td class="px-5 py-3">{{ $l->modelName() }}
                    @if ($l->auditable_label)<span class="text-slate-500">· {{ $l->auditable_label }}</span>
                    @elseif ($l->auditable_id)<span class="text-slate-500">#{{ $l->auditable_id }}</span>@endif</td>
                <td class="px-5 py-3 text-slate-500">{{ $l->ip_address }}</td>
                <td class="px-5 py-3 text-right"><x-button :href="route('audit.show', $l)" variant="ghost" size="sm">Details</x-button></td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No entries match your filters.</td></tr>
        @endforelse
    </x-table>
    @if ($logs->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $logs->links() }}</div>@endif
</x-card>
@endsection
