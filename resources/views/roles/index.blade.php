@extends('layouts.app')
@section('title', 'Roles')

@section('content')
<x-page-header title="Roles & access" description="Group permissions into roles and assign them to users.">
    @can('roles.create')
        <x-slot:actions><x-button :href="route('roles.create')"><x-icon name="plus" class="size-4" /> New role</x-button></x-slot:actions>
    @endcan
</x-page-header>

<x-card :padding="false">
    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">Role</th>
                <th class="px-5 py-3 font-medium">Users</th>
                <th class="px-5 py-3 font-medium">Permissions</th>
                <th class="px-5 py-3 font-medium">Status</th>
                <th class="px-5 py-3 text-right font-medium">Actions</th>
            </tr>
        </x-slot:head>
        @foreach ($roles as $role)
            <tr>
                <td class="px-5 py-3">
                    <a href="{{ route('roles.show', $role) }}" class="font-medium text-slate-900 hover:underline">{{ $role->name }}</a>
                    @if ($role->isSuperAdmin())<x-badge color="brand" class="ml-1">System</x-badge>@endif
                    @if ($role->description)<p class="text-xs text-slate-500">{{ $role->description }}</p>@endif
                </td>
                <td class="px-5 py-3">{{ $role->users_count }}</td>
                <td class="px-5 py-3">{{ $role->permissions_count }}</td>
                <td class="px-5 py-3"><x-badge :color="$role->is_active ? 'green' : 'slate'">{{ $role->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                <td class="px-5 py-3">
                    <div class="flex justify-end gap-1">
                        <x-button :href="route('roles.show', $role)" variant="ghost" size="sm">View</x-button>
                        @unless ($role->isSuperAdmin())
                            @can('roles.edit')<x-button :href="route('roles.edit', $role)" variant="ghost" size="sm">Edit</x-button>@endcan
                            @can('roles.toggle')
                                <x-confirm-action :action="route('roles.toggle', $role)" method="PATCH"
                                    :title="$role->is_active ? 'Deactivate role?' : 'Activate role?'" :confirm="$role->is_active ? 'Deactivate' : 'Activate'" :variant="$role->is_active ? 'danger' : 'primary'">
                                    <x-slot:trigger><x-button variant="ghost" size="sm">{{ $role->is_active ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
                                    {{ $role->is_active
                                        ? "Permissions from this role will stop applying to its {$role->users_count} user(s) immediately. Assignments are kept."
                                        : 'Permissions from this role will apply again to its users.' }}
                                </x-confirm-action>
                            @endcan
                        @endunless
                    </div>
                </td>
            </tr>
        @endforeach
    </x-table>
</x-card>
@endsection
