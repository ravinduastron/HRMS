@extends('layouts.app')
@section('title', $user->name)

@section('content')
<x-page-header :title="$user->name" :description="'@'.$user->username">
    <x-slot:actions>
        @can('users.edit')<x-button :href="route('users.edit', $user)" variant="secondary">Edit</x-button>@endcan
        @if (! $user->is(auth()->user()))
            @can('users.reset-password')
                <x-confirm-action :action="route('users.reset-password', $user)" title="Reset password?" confirm="Reset & email password" variant="primary">
                    <x-slot:trigger><x-button variant="secondary">Reset password</x-button></x-slot:trigger>
                    A new temporary password will be generated and emailed to {{ $user->email }}. They must change it at next sign-in.
                </x-confirm-action>
            @endcan
            @can('users.toggle')
                <x-confirm-action :action="route('users.toggle', $user)" method="PATCH"
                    :title="$user->is_active ? 'Deactivate user?' : 'Activate user?'" :confirm="$user->is_active ? 'Deactivate' : 'Activate'" :variant="$user->is_active ? 'danger' : 'primary'">
                    <x-slot:trigger><x-button :variant="$user->is_active ? 'danger' : 'primary'">{{ $user->is_active ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
                    {{ $user->is_active ? 'This user will be signed out and will no longer be able to sign in.' : 'This user will be able to sign in again.' }}
                </x-confirm-action>
            @endcan
        @endif
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card title="Details">
        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-slate-500">Email</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $user->email }}</dd></div>
            <div><dt class="text-slate-500">Status</dt><dd class="mt-0.5">
                @if ($user->isLocked())<x-badge color="red">Locked until {{ $user->locked_until->format('H:i') }}</x-badge>
                @elseif ($user->is_active)<x-badge color="green">Active</x-badge>@else<x-badge>Inactive</x-badge>@endif</dd></div>
            <div><dt class="text-slate-500">Password</dt><dd class="mt-0.5 font-medium text-slate-900">
                @if ($user->must_change_password)<x-badge color="amber">Temporary: change pending</x-badge>
                @else Changed {{ $user->password_changed_at?->diffForHumans() ?? 'never' }}@endif</dd></div>
            <div><dt class="text-slate-500">Employee schemes</dt><dd class="mt-0.5 font-medium text-slate-900">
                @if ($user->can('schemes.all'))<x-badge color="brand">All schemes</x-badge>
                @elseif ($user->schemes->isEmpty())<span class="text-amber-700">None: sees no employees</span>
                @else{{ $user->schemes->pluck('name')->sort()->implode(', ') }}@endif</dd></div>
            <div><dt class="text-slate-500">Account created</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $user->created_at?->format('d M Y') }}</dd></div>
        </dl>
    </x-card>

    @include('users._access')

    @can('audit.view')<x-audit-timeline :model="$user" />@endcan
</div>
@endsection
