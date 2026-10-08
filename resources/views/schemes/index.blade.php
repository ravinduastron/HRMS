@extends('layouts.app')
@section('title', 'Schemes')

@section('content')
<x-page-header title="Schemes" description="Employee groups. Users only see and manage employees in the schemes allocated to them." />

@if ($schemes->contains(fn ($s) => $s->users_count === 0 && $s->employees_count > 0))
    <x-alert type="warning" class="mb-4">Some schemes have employees but no users allocated, so only users with all-scheme access can see those employees.</x-alert>
@endif

<x-card :padding="false">
    <x-table>
        <x-slot:head>
            <tr><th class="px-5 py-3 font-medium">Scheme</th><th class="px-5 py-3 font-medium">Source</th>
                <th class="px-5 py-3 font-medium">Employees</th><th class="px-5 py-3 font-medium">Users with access</th><th class="px-5 py-3"></th></tr>
        </x-slot:head>
        @forelse ($schemes as $s)
            <tr>
                <td class="px-5 py-3"><a href="{{ route('schemes.show', $s) }}" class="font-medium text-slate-900 hover:underline">{{ $s->name }}</a>
                    @if ($s->description)<p class="text-xs text-slate-500">{{ $s->description }}</p>@endif</td>
                <td class="px-5 py-3"><x-badge :color="$s->source === 'mihcm' ? 'brand' : 'slate'">{{ $s->source === 'mihcm' ? 'MiHCM' : 'Local' }}</x-badge></td>
                <td class="px-5 py-3">{{ $s->employees_count }}</td>
                <td class="px-5 py-3">@if ($s->users_count){{ $s->users_count }}@else<span class="text-amber-700">None</span>@endif</td>
                <td class="px-5 py-3 text-right"><x-button :href="route('schemes.show', $s)" variant="ghost" size="sm">Manage</x-button></td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No schemes yet. They are created automatically by the MiHCM sync.</td></tr>
        @endforelse
    </x-table>
</x-card>
@endsection
