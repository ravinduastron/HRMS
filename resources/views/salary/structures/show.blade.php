@extends('layouts.app')
@section('title', $structure->name)

@section('content')
<x-page-header :title="$structure->name" :description="$structure->scheme->name.' scheme · '.$assigned.' '.Str::plural('employee', $assigned).' started from this structure'">
    <x-slot:actions>
        <x-button :href="route('salary-structures.index')" variant="secondary">All structures</x-button>
        @can('salary-structures.manage')
            <x-button :href="route('salary-structures.edit', $structure)" variant="secondary">Edit</x-button>
            <x-confirm-action :action="route('salary-structures.toggle', $structure)" method="PATCH" :title="($structure->is_active ? 'Deactivate ' : 'Activate ').$structure->name.'?'"
                :confirm="$structure->is_active ? 'Deactivate' : 'Activate'" :variant="$structure->is_active ? 'danger' : 'primary'">
                <x-slot:trigger><x-button :variant="$structure->is_active ? 'danger' : 'primary'">{{ $structure->is_active ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
                {{ $structure->is_active ? 'It can no longer be assigned to employees. Employees already assigned keep their components.' : 'It can be assigned to employees again.' }}
            </x-confirm-action>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card title="Details">
        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
            <div><dt class="text-slate-500">Scheme</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $structure->scheme->name }}</dd></div>
            <div><dt class="text-slate-500">Status</dt><dd class="mt-0.5"><x-badge :color="$structure->is_active ? 'green' : 'slate'">{{ $structure->is_active ? 'Active' : 'Inactive' }}</x-badge></dd></div>
            <div><dt class="text-slate-500">Description</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $structure->description ?: '—' }}</dd></div>
        </dl>
    </x-card>

    <x-card title="Components" :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">Component</th><th class="px-5 py-3 font-medium">Type</th><th class="px-5 py-3 font-medium">Calculation</th><th class="px-5 py-3 text-right font-medium">Default amount</th></tr>
            </x-slot:head>
            @foreach ($structure->lines as $l)
                @php $c = $l->component; $v = $c->latestVersion(); @endphp
                <tr>
                    <td class="px-5 py-3"><span class="font-mono text-xs font-medium text-slate-900">{{ $c->code }}</span><span class="block text-xs text-slate-500">{{ $c->name }}</span>
                        @unless ($c->is_active)<x-badge color="amber">Inactive</x-badge>@endunless</td>
                    <td class="px-5 py-3">{{ \App\Models\PayComponent::TYPES[$c->type] }}</td>
                    <td class="px-5 py-3 text-xs text-slate-600">{{ $v ? explode(' ', \App\Models\PayComponentVersion::METHODS[$v->calc_method])[0] : '—' }}</td>
                    <td class="px-5 py-3 text-right font-mono">{{ $l->default_amount !== null ? number_format($l->default_amount, 2) : '—' }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</div>
@endsection
