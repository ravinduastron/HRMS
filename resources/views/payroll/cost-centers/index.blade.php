@extends('layouts.app')
@section('title', 'Cost centers')

@section('content')
@php
    $user = auth()->user();
    $canManage = $user->can('cost-centers.manage') && $user->can('schemes.all');
    $max = config('costcenters.max_level');
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $levelName = fn ($l) => config('costcenters.levels.'.$l, 'Level '.$l);
    $parents = collect($flat)->filter(fn ($f) => $f['node']['is_active'] && $f['node']['level'] < $max);
@endphp
<x-page-header title="Cost centers" description="A tree of up to {{ $max }} levels ({{ implode(' > ', config('costcenters.levels')) }}). Employees are assigned to the lowest level of their branch.">
    <x-slot:actions>
        @can('cost-centers.assign')<x-button :href="route('cost-centers.assign')" variant="secondary">Assign to employees</x-button>@endcan
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    @if ($unassigned > 0)
        <x-alert type="warning">{{ $unassigned }} payroll employee(s) have no cost center yet. A pay run cannot be made for them until one is assigned.</x-alert>
    @endif

    @if ($canManage)
        <x-card title="Add a cost center">
            <form method="POST" action="{{ route('cost-centers.store') }}" class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @csrf
                <div class="space-y-1.5">
                    <label for="parent_id" class="block text-sm font-medium text-slate-700">Under</label>
                    <select id="parent_id" name="parent_id" class="{{ $field }}">
                        <option value="">Top level ({{ $levelName(1) }})</option>
                        @foreach ($parents as $f)
                            <option value="{{ $f['node']['id'] }}" @selected((string) old('parent_id') === (string) $f['node']['id'])>{{ str_repeat('— ', $f['depth']) }}{{ $f['node']['name'] }} (adds a {{ $levelName($f['node']['level'] + 1) }})</option>
                        @endforeach
                    </select>
                    @error('parent_id')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-input name="code" label="Code" :value="old('code')" required autocomplete="off" placeholder="e.g. MFG-PACK" hint="Cannot be changed later." />
                <x-input name="name" label="Name" :value="old('name')" required placeholder="e.g. Packing" />
                <div class="lg:pt-[1.625rem]"><x-button type="submit">Add</x-button></div>
            </form>
        </x-card>
    @endif

    <x-card :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Cost center</th><th class="px-5 py-3 font-medium">Code</th><th class="px-5 py-3 font-medium">Level</th>
                    <th class="px-5 py-3 text-right font-medium">Employees</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3 text-right font-medium">Actions</th>
                </tr>
            </x-slot:head>
            @forelse ($flat as $f)
                @php $n = $f['node']; @endphp
                <tr>
                    <td class="py-3 pr-5 font-medium text-slate-900" style="padding-left: {{ 1.25 + $f['depth'] * 1.75 }}rem">{{ $f['depth'] ? '└ ' : '' }}{{ $n['name'] }}</td>
                    <td class="px-5 py-3 font-mono text-xs">{{ $n['code'] }}</td>
                    <td class="px-5 py-3 text-slate-600">{{ $levelName($n['level']) }}</td>
                    <td class="px-5 py-3 text-right">{{ $counts[$n['id']] ?? 0 }}</td>
                    <td class="px-5 py-3"><x-badge :color="$n['is_active'] ? 'green' : 'slate'">{{ $n['is_active'] ? 'Active' : 'Inactive' }}</x-badge></td>
                    <td class="px-5 py-3">
                        @if ($canManage)
                            <div class="flex justify-end gap-1">
                                <x-confirm-action :action="route('cost-centers.update', $n['id'])" method="PUT" :title="'Rename '.$n['name'].'?'" confirm="Rename" variant="primary">
                                    <x-slot:trigger><x-button variant="ghost" size="sm">Rename</x-button></x-slot:trigger>
                                    The code stays the same. Finished pay runs keep the name they were calculated with.
                                    <input type="text" name="name" value="{{ $n['name'] }}" maxlength="100" required class="{{ $field }} mt-3">
                                </x-confirm-action>
                                <x-confirm-action :action="route('cost-centers.toggle', $n['id'])" method="PATCH" :title="($n['is_active'] ? 'Deactivate ' : 'Activate ').$n['name'].'?'"
                                    :confirm="$n['is_active'] ? 'Deactivate' : 'Activate'" :variant="$n['is_active'] ? 'danger' : 'primary'">
                                    <x-slot:trigger><x-button variant="ghost" size="sm">{{ $n['is_active'] ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
                                    {{ $n['is_active'] ? 'It can no longer be chosen. This is refused while it has active sub-levels or employees.' : 'It can be chosen again.' }}
                                </x-confirm-action>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No cost centers yet.@if ($canManage) Add the first one above.@endif</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
@endsection
