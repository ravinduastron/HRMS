@extends('layouts.app')
@section('title', 'Salary structures')

@section('content')
@php $field = 'rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:outline-brand-600'; @endphp
<x-page-header title="Salary structures" description="Templates of pay components for a scheme. Assigning one to an employee copies its components; later edits never change employees already assigned.">
    @can('salary-structures.manage')
        <x-slot:actions><x-button :href="route('salary-structures.create')"><x-icon name="plus" class="size-4" /> New structure</x-button></x-slot:actions>
    @endcan
</x-page-header>

<x-card :padding="false">
    <form method="GET" class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
        <div class="w-56">
            <x-combobox name="scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="request('scheme')" empty="All schemes" />
        </div>
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
                <th class="px-5 py-3 font-medium">Structure</th>
                <th class="px-5 py-3 font-medium">Scheme</th>
                <th class="px-5 py-3 font-medium">Components</th>
                <th class="px-5 py-3 font-medium">Employees</th>
                <th class="px-5 py-3 font-medium">Status</th>
                <th class="px-5 py-3"></th>
            </tr>
        </x-slot:head>
        @forelse ($structures as $s)
            <tr>
                <td class="px-5 py-3"><a href="{{ route('salary-structures.show', $s) }}" class="font-medium text-slate-900 hover:underline">{{ $s->name }}</a>
                    @if ($s->description)<p class="text-xs text-slate-500">{{ $s->description }}</p>@endif</td>
                <td class="px-5 py-3">{{ $s->scheme->name }}</td>
                <td class="px-5 py-3">{{ $s->lines_count }}</td>
                <td class="px-5 py-3">{{ $assigned[$s->id] ?? 0 }}</td>
                <td class="px-5 py-3"><x-badge :color="$s->is_active ? 'green' : 'slate'">{{ $s->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                <td class="px-5 py-3 text-right">
                    <div class="flex justify-end gap-1">
                        <x-button :href="route('salary-structures.show', $s)" variant="ghost" size="sm">Open</x-button>
                        @can('salary-structures.manage')<x-button :href="route('salary-structures.edit', $s)" variant="ghost" size="sm">Edit</x-button>@endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No salary structures for your schemes yet.</td></tr>
        @endforelse
    </x-table>
</x-card>
@endsection
