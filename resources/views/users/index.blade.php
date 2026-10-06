@extends('layouts.app')
@section('title', 'Users')

@section('content')
<x-page-header title="Users" description="Manage who can sign in and what they can do.">
    @can('users.create')
        <x-slot:actions><x-button :href="route('users.create')"><x-icon name="plus" class="size-4" /> New user</x-button></x-slot:actions>
    @endcan
</x-page-header>

<x-card :padding="false">
    <form method="GET" class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
        <div class="w-full sm:w-72">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name, username or email"
                class="block w-full rounded-md border border-slate-300 py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600">
        </div>
        <select name="status" class="rounded-md border border-slate-300 py-2 pl-3 pr-8 text-sm focus:outline-2 focus:outline-brand-600">
            <option value="">All statuses</option>
            <option value="active" @selected(request('status') === 'active')>Active</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
        </select>
        <x-button type="submit" variant="secondary">Filter</x-button>
        @if (request()->hasAny(['q', 'status']))<a href="{{ route('users.index') }}" class="text-sm text-slate-500 hover:underline">Clear</a>@endif
    </form>

    <x-table>
        <x-slot:head>
            <tr>
                <th class="px-5 py-3 font-medium">User</th>
                <th class="px-5 py-3 font-medium">Username</th>
                <th class="px-5 py-3 font-medium">Roles</th>
                <th class="px-5 py-3 font-medium">Status</th>
                <th class="px-5 py-3 text-right font-medium">Actions</th>
            </tr>
        </x-slot:head>
        @forelse ($users as $u)
            <tr>
                <td class="px-5 py-3">
                    <div class="flex items-center gap-3">
                        <x-avatar :name="$u->name" />
                        <div class="min-w-0">
                            <a href="{{ route('users.show', $u) }}" class="font-medium text-slate-900 hover:underline">{{ $u->name }}</a>
                            <p class="truncate text-xs text-slate-500">{{ $u->email }}</p>
                        </div>
                    </div>
                </td>
                <td class="px-5 py-3">{{ $u->username }}</td>
                <td class="px-5 py-3">
                    <div class="flex flex-wrap gap-1">
                        @forelse ($u->roles as $r)<x-badge :color="$r->is_active ? 'brand' : 'slate'">{{ $r->name }}</x-badge>@empty<span class="text-slate-400">—</span>@endforelse
                    </div>
                </td>
                <td class="px-5 py-3">
                    @if ($u->isLocked())<x-badge color="red">Locked</x-badge>
                    @elseif ($u->is_active)<x-badge color="green">Active</x-badge>
                    @else<x-badge>Inactive</x-badge>@endif
                </td>
                <td class="px-5 py-3">
                    <div class="flex justify-end gap-1">
                        <x-button :href="route('users.show', $u)" variant="ghost" size="sm">View</x-button>
                        @can('users.edit')<x-button :href="route('users.edit', $u)" variant="ghost" size="sm">Edit</x-button>@endcan
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No users match your filters.</td></tr>
        @endforelse
    </x-table>
    @if ($users->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $users->links() }}</div>@endif
</x-card>
@endsection
