@extends('layouts.app')
@section('title', $scheme->name)

@section('content')
@php $hasPay = $scheme->paySettings()->exists(); @endphp
<x-page-header :title="$scheme->name" :description="$scheme->employees_count.' '.Str::plural('employee', $scheme->employees_count)">
    <x-slot:actions>
        <x-button :href="route('schemes.pay.index', $scheme)" variant="secondary">Pay settings</x-button>
        <x-button :href="route('employees.index', ['scheme' => $scheme->id])" variant="secondary">View employees</x-button>
        <x-button :href="route('schemes.index')" variant="secondary">All schemes</x-button>
    </x-slot:actions>
</x-page-header>

@unless ($hasPay)
    <x-alert type="warning" class="mb-4">This scheme has no pay settings yet (pay cycles, windows and pay dates), so it cannot be used in a pay run. <a href="{{ route('schemes.pay.index', $scheme) }}" class="font-medium underline">Set them up</a>.</x-alert>
@endunless

<div class="space-y-6">
    <x-card title="Details">
        <form method="POST" action="{{ route('schemes.update', $scheme) }}" class="grid gap-5 sm:grid-cols-2">
            @csrf @method('PUT')
            <x-input name="name" label="Display name" :value="old('name', $scheme->name)" required />
            <x-input name="description" label="Description" :value="old('description', $scheme->description)" />
            <div class="sm:col-span-2">
                <x-input name="mihcm_name" label="MiHCM scheme name" :value="old('mihcm_name', $scheme->mihcm_name)" :required="$scheme->source === 'mihcm'"
                    hint="The sync matches employees to this scheme using this name. If MiHCM renames the scheme, update it here so user access is kept. Employees move to this scheme on the next sync." />
            </div>
            @can('schemes.edit')<div class="flex justify-end sm:col-span-2"><x-button type="submit">Save details</x-button></div>@endcan
        </form>
    </x-card>

    <x-card title="Users with access">
        <form method="POST" action="{{ route('schemes.members', $scheme) }}">
            @csrf @method('PUT')
            <p class="mb-3 text-sm text-slate-500">Ticked users can see and manage the employees in this scheme (what they can DO still depends on their roles).</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($users as $u)
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50 has-checked:border-brand-500 has-checked:bg-brand-50">
                        <input type="checkbox" name="user_ids[]" value="{{ $u->id }}" @checked(in_array($u->id, old('user_ids', $memberIds))) class="size-4 accent-brand-700">
                        <span><span class="font-medium text-slate-900">{{ $u->name }}</span><span class="block text-xs text-slate-500">{{ $u->username }}</span></span>
                    </label>
                @endforeach
            </div>
            @can('schemes.allocate')<div class="mt-5 flex justify-end"><x-button type="submit">Save access</x-button></div>@endcan
        </form>
    </x-card>
</div>
@endsection
