<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('payroll.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-50 pt-14 font-sans text-slate-800 antialiased"
    x-data="{
        sidebarOpen: false,
        collapsed: JSON.parse(localStorage.getItem('nav.collapsed') ?? 'false'),
        get compact() { return this.collapsed && !this.sidebarOpen },
    }"
    x-init="$watch('collapsed', v => localStorage.setItem('nav.collapsed', JSON.stringify(v)))"
    @keydown.escape.window="sidebarOpen = false">

    @include('partials.topbar')
    @include('partials.sidebar')

    <main class="transition-[padding] duration-200" :class="collapsed ? 'lg:pl-[4.5rem]' : 'lg:pl-64'">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            @include('partials.flash')
            @yield('content')
        </div>
    </main>
    <x-toast-container />
</body>
</html>
