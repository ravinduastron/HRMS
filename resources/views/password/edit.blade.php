@extends('layouts.app')
@section('title', 'Change password')
@section('content')
<x-page-header title="Change password" :description="$forced ? 'You must set a new password before continuing.' : null" />
<div class="grid gap-6 lg:grid-cols-3">
    <x-card class="lg:col-span-2">
        <form method="POST" action="{{ route('password.update') }}" class="max-w-md space-y-5">
            @csrf @method('PUT')
            <x-input name="current_password" type="password" label="Current password" autocomplete="current-password" required />
            <x-input name="password" type="password" label="New password" autocomplete="new-password" required />
            <x-input name="password_confirmation" type="password" label="Confirm new password" autocomplete="new-password" required />
            <div class="flex gap-2">
                <x-button type="submit">Update password</x-button>
                @unless ($forced)<x-button :href="route('profile')" variant="secondary">Cancel</x-button>@endunless
            </div>
        </form>
    </x-card>
    <x-card title="Password policy">
        <ul class="list-disc space-y-1.5 pl-4 text-sm text-slate-600">
            <li>At least {{ config('password_policy.min_length') }} characters</li>
            <li>Upper and lower case letters, a number and a symbol</li>
            <li>No part of your name or username</li>
            <li>Not one of your last {{ config('password_policy.history') }} passwords</li>
            <li>Can be changed once every {{ config('password_policy.min_age_days') }} day</li>
            <li>Expires every {{ config('password_policy.max_age_days') }} days</li>
        </ul>
    </x-card>
</div>
@endsection
