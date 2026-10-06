@extends('layouts.guest')

@section('content')
<div class="rounded-xl bg-white p-8 shadow-lg ring-1 ring-slate-900/5 sm:p-10">
    <div class="flex flex-col items-center text-center">
        {{-- Set PAYROLL_LOGO in .env to show your company logo here --}}
        <x-brand-logo size="h-14" />
        <h1 class="mt-6 text-xl font-semibold tracking-tight text-slate-900">Sign in to {{ config('payroll.name') }}</h1>
        <p class="mt-1 text-sm text-slate-500">Use the username issued by your administrator.</p>
    </div>

    <form method="POST" action="{{ route('login.store') }}" x-data="{ loading: false }" @submit="loading = true" class="mt-8 space-y-5">
        @csrf


        <x-input name="username" label="Username" icon="user" autocomplete="username" required autofocus
            :value="old('username')" placeholder="e.g. jsmith" />

        <x-input name="password" type="password" label="Password" icon="lock" autocomplete="current-password" required />

        <div class="flex items-center justify-between">
            <x-checkbox name="remember" label="Keep me signed in" />
            <a href="#" class="text-sm font-medium text-brand-700 hover:underline">Forgot password?</a>
        </div>

        <x-button type="submit" size="lg" class="w-full" loading="loading">Sign in</x-button>
    </form>
</div>
@endsection
