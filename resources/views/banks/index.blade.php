@extends('layouts.app')
@section('title', 'Banks & branches')

@section('content')
<x-page-header title="Banks & branches" description="Master list used when adding employee bank accounts. Entries can be added or removed, not edited." />

<div class="space-y-6">
    @can('bank-master.add')
        <x-card title="Add a bank">
            <form method="POST" action="{{ route('banks.store') }}" class="grid items-start gap-4 sm:grid-cols-[10rem_1fr_auto]">
                @csrf
                <x-input name="code" label="Bank code" placeholder="e.g. 7010" required />
                <x-input name="name" label="Bank name" placeholder="e.g. Bank of Ceylon" required />
                <div class="sm:pt-[1.625rem]"><x-button type="submit">Add bank</x-button></div>
            </form>
        </x-card>
    @endcan

    <x-card :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">Code</th><th class="px-5 py-3 font-medium">Bank</th><th class="px-5 py-3 font-medium">Branches</th>
                    <th class="px-5 py-3 font-medium">Accounts</th><th class="px-5 py-3 text-right font-medium">Actions</th></tr>
            </x-slot:head>
            @forelse ($banks as $b)
                <tr>
                    <td class="px-5 py-3 font-mono">{{ $b->code }}</td>
                    <td class="px-5 py-3 font-medium text-slate-900"><a href="{{ route('banks.show', $b) }}" class="hover:underline">{{ $b->name }}</a></td>
                    <td class="px-5 py-3">{{ $b->branches_count }}</td>
                    <td class="px-5 py-3">{{ $b->accounts_count }}</td>
                    <td class="px-5 py-3">
                        <div class="flex justify-end gap-1">
                            <x-button :href="route('banks.show', $b)" variant="ghost" size="sm">Branches</x-button>
                            @can('bank-master.remove')
                                @if ($b->accounts_count === 0)
                                    <x-confirm-action :action="route('banks.destroy', $b)" method="DELETE" :title="'Remove '.$b->name.'?'" confirm="Remove bank">
                                        <x-slot:trigger><x-button variant="ghost" size="sm"><span class="text-red-600">Remove</span></x-button></x-slot:trigger>
                                        This also removes its {{ $b->branches_count }} {{ Str::plural('branch', $b->branches_count) }}. It cannot be edited or restored; add it again if needed.
                                    </x-confirm-action>
                                @else
                                    <span class="px-2 py-1 text-xs text-slate-400" title="Used by employee accounts">In use</span>
                                @endif
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No banks yet.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
@endsection
