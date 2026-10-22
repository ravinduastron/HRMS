@extends('layouts.app')

@section('content')
<x-page-header :title="'Welcome back, '.explode(' ', auth()->user()->name)[0]" description="A summary of the employees and set-up you can see." />

@if ($noAccess)
    <x-alert type="warning" class="mb-4">No schemes are allocated to you, so you cannot see any employees yet. Ask an administrator to allocate your schemes.</x-alert>
@endif

<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    @foreach ($cards as $c)
        <x-stat-card :label="$c['label']" :value="$c['value']" :delta="$c['delta']" :trend="$c['trend']" :icon="$c['icon']" :href="$c['href']" />
    @endforeach
</div>
@endsection
