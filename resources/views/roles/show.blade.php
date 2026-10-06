@extends('layouts.app')
@section('title', $role->name)

@section('content')
@php $granted = $role->permissions->pluck('name')->all(); @endphp
<x-page-header :title="$role->name" :description="$role->description">
    <x-slot:actions>
        <x-badge :color="$role->is_active ? 'green' : 'slate'">{{ $role->is_active ? 'Active' : 'Inactive' }}</x-badge>
        @if (! $role->isSuperAdmin())
            @can('roles.edit')<x-button :href="route('roles.edit', $role)" variant="secondary">Edit</x-button>@endcan
        @endif
    </x-slot:actions>
</x-page-header>

@if ($role->isSuperAdmin())
    <x-alert type="info" class="mb-4">Super Admin is a system role. It always holds every permission and cannot be edited or deactivated.</x-alert>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <x-card title="Permissions" class="lg:col-span-2">
        @foreach (config('permissions') as $group => $perms)
            <div class="mb-5 last:mb-0">
                <p class="mb-1.5 text-xs font-medium text-slate-500">{{ $group }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($perms as $name => $label)
                        <x-badge :color="in_array($name, $granted) ? 'brand' : 'slate'" @class(['opacity-40 line-through' => ! in_array($name, $granted)])>{{ $label }}</x-badge>
                    @endforeach
                </div>
            </div>
        @endforeach
    </x-card>

    <x-card title="Users with this role ({{ $role->users->count() }})">
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse ($role->users as $u)
                <li class="py-2"><a href="{{ route('users.show', $u) }}" class="font-medium text-slate-900 hover:underline">{{ $u->name }}</a>
                    <span class="block text-xs text-slate-500">{{ $u->email }}</span></li>
            @empty
                <li class="py-2 text-slate-500">No users yet.</li>
            @endforelse
        </ul>
    </x-card>
</div>
@endsection
