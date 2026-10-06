<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sign in') · {{ config('payroll.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="relative min-h-full bg-slate-100 font-sans text-slate-800 antialiased">
    <div class="absolute inset-x-0 top-0 h-64 bg-brand-950" aria-hidden="true"></div>
    <main class="relative grid min-h-screen place-items-center px-4 py-12">
        <div class="w-full max-w-md">
            @yield('content')
            <p class="mt-6 text-center text-xs text-slate-500">&copy; {{ date('Y') }} {{ config('payroll.company') }}</p>
        </div>
    </main>
</body>
</html>
