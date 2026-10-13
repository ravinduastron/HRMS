@extends('layouts.app')
@section('title', 'Component groups')

@section('content')
@php $typeColor = ['earning' => 'green', 'deduction' => 'red', 'employer_contribution' => 'brand', 'info' => 'slate']; @endphp
<x-page-header title="Pay component groups" description="Every pay component belongs to one group. A group can print as a single summed line on the payslip." />

<div class="space-y-6">
    @can('pay-component-groups.manage')
        <x-card title="Add a group">@include('payroll.groups._form')</x-card>
    @endcan

    <x-card :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Group</th>
                    <th class="px-5 py-3 font-medium">Type</th>
                    <th class="px-5 py-3 font-medium">On the payslip</th>
                    <th class="px-5 py-3 font-medium">Components</th>
                    <th class="px-5 py-3 font-medium">Status</th>
                    <th class="px-5 py-3 text-right font-medium">Actions</th>
                </tr>
            </x-slot:head>
            @forelse ($groups as $g)
                <tr>
                    <td class="px-5 py-3 font-medium text-slate-900">{{ $g->name }}
                        @if ($g->description)<p class="text-xs font-normal text-slate-500">{{ $g->description }}</p>@endif</td>
                    <td class="px-5 py-3"><x-badge :color="$typeColor[$g->type] ?? 'slate'">{{ \App\Models\PayComponent::TYPES[$g->type] ?? $g->type }}</x-badge></td>
                    <td class="px-5 py-3">{{ $g->combine_on_payslip ? 'One summed line' : 'Separate lines' }}</td>
                    <td class="px-5 py-3">{{ $g->components_count }}@if ($g->components_count !== $g->active_components_count)<span class="text-xs text-slate-500"> ({{ $g->active_components_count }} active)</span>@endif</td>
                    <td class="px-5 py-3"><x-badge :color="$g->is_active ? 'green' : 'slate'">{{ $g->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                    <td class="px-5 py-3">
                        @can('pay-component-groups.manage')
                            <div class="flex justify-end gap-1">
                                <x-button :href="route('pay-component-groups.edit', $g)" variant="ghost" size="sm">Edit</x-button>
                                @if ($g->is_active && $g->active_components_count > 0)
                                    <span class="px-2 py-1 text-xs text-slate-400" title="Has active components">In use</span>
                                @else
                                    <x-confirm-action :action="route('pay-component-groups.toggle', $g)" method="PATCH" :title="($g->is_active ? 'Deactivate ' : 'Activate ').$g->name.'?'"
                                        :confirm="$g->is_active ? 'Deactivate' : 'Activate'" :variant="$g->is_active ? 'danger' : 'primary'">
                                        <x-slot:trigger><x-button variant="ghost" size="sm">{{ $g->is_active ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
                                        {{ $g->is_active ? 'It can no longer be chosen for new components.' : 'It can be chosen for components again.' }}
                                    </x-confirm-action>
                                @endif
                            </div>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No groups yet.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
@endsection
