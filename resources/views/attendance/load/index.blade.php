@extends('layouts.app')
@section('title', 'Load attendance & leave')

@section('content')
@php
    $color = ['queued' => 'slate', 'running' => 'amber', 'completed' => 'green', 'failed' => 'red'];
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $scopeLabel = ['attendance' => 'Attendance', 'leave' => 'Leave', 'both' => 'Attendance and leave'];
@endphp
@if ($runs->contains(fn ($r) => $r->isRunning()))<div x-data x-init="setTimeout(() => location.reload(), 5000)"></div>@endif

<x-page-header title="Load attendance & leave" description="Pull attendance and leave from MiHCM for a date range. Loads are manual, and a pay run will use the same loader." />

<div class="space-y-6">
    @can('attendance.load')
        <x-card title="New load">
            <form method="POST" action="{{ route('attendance.load.store') }}" class="grid gap-5 sm:grid-cols-4">
                @csrf
                <x-input name="from" type="date" label="From" :value="old('from', $defaultFrom)" required />
                <x-input name="to" type="date" label="To" :value="old('to', $defaultTo)" required />
                <div class="space-y-1.5">
                    <label for="scope" class="block text-sm font-medium text-slate-700">What to load</label>
                    <select id="scope" name="scope" class="{{ $field }}">
                        @foreach ($scopeLabel as $k => $t)<option value="{{ $k }}" @selected(old('scope', 'both') === $k)>{{ $t }}</option>@endforeach
                    </select>
                </div>
                <div class="flex items-end"><x-button type="submit" class="w-full">Load data</x-button></div>
            </form>
            <p class="mt-4 text-sm text-slate-500">
                At most {{ config('attendance.max_days') }} days per load. MiHCM does not say which data is final: a load never makes data final.
                Open the result to see what still needs attention (pending leave, unapproved overtime, new leave types), and load again whenever MiHCM changes.
                Data for the current month may still change.
            </p>
        </x-card>
    @endcan

    <x-card title="Load history" :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Load</th><th class="px-5 py-3 font-medium">Started</th><th class="px-5 py-3 font-medium">By</th>
                    <th class="px-5 py-3 font-medium">Range</th><th class="px-5 py-3 font-medium">What</th><th class="px-5 py-3 font-medium">Status</th>
                    <th class="px-5 py-3 text-right font-medium">Needs attention</th><th class="px-5 py-3"></th>
                </tr>
            </x-slot:head>
            @forelse ($runs as $r)
                @php $warnings = collect($r->quality ?? [])->where('severity', 'warning')->count(); @endphp
                <tr>
                    <td class="px-5 py-3 font-medium text-slate-900">#{{ $r->id }}</td>
                    <td class="whitespace-nowrap px-5 py-3 text-slate-500">{{ ($r->started_at ?? $r->created_at)->format('d M Y H:i') }}@if ($r->durationSeconds() !== null)<span class="text-xs"> · {{ $r->durationSeconds() }}s</span>@endif</td>
                    <td class="px-5 py-3">{{ $r->triggered_by_name ?? ucfirst($r->trigger) }}</td>
                    <td class="whitespace-nowrap px-5 py-3">{{ $r->from_date->format('d M Y') }} – {{ $r->to_date->format('d M Y') }}</td>
                    <td class="px-5 py-3">{{ $scopeLabel[$r->scope] ?? $r->scope }}</td>
                    <td class="px-5 py-3"><x-badge :color="$color[$r->status] ?? 'slate'">{{ ucfirst($r->status) }}</x-badge></td>
                    <td class="px-5 py-3 text-right">@if ($r->status === 'completed')@if ($warnings)<x-badge color="amber">{{ $warnings }} {{ Str::plural('item', $warnings) }}</x-badge>@else<span class="text-xs text-slate-400">None</span>@endif @endif</td>
                    <td class="px-5 py-3 text-right"><x-button :href="route('attendance.load.show', $r)" variant="ghost" size="sm">Details</x-button></td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-5 py-10 text-center text-slate-500">No loads yet.</td></tr>
            @endforelse
        </x-table>
        @if ($runs->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $runs->links() }}</div>@endif
    </x-card>
</div>
@endsection
