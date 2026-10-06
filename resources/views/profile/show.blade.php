@extends('layouts.app')
@section('title', 'My profile')
@section('content')
<x-page-header title="My profile" :description="$user->email">
    <x-slot:actions><x-button :href="route('password.edit')" variant="secondary"><x-icon name="lock" class="size-4" /> Change password</x-button></x-slot:actions>
</x-page-header>
<div class="space-y-6">
    <x-card title="Account">
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-slate-500">Name</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $user->name }}</dd></div>
            <div><dt class="text-slate-500">Username</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $user->username }}</dd></div>
            <div><dt class="text-slate-500">Password expires</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $user->password_changed_at?->copy()->addDays(config('password_policy.max_age_days'))->format('d M Y') ?? '—' }}</dd></div>
        </dl>
    </x-card>
    @include('users._access')
</div>
@endsection
