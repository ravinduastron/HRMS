@extends('layouts.app')
@section('title', $bank->name)

@section('content')
<x-page-header :title="$bank->name" :description="'Bank code '.$bank->code">
    <x-slot:actions><x-button :href="route('banks.index')" variant="secondary">All banks</x-button></x-slot:actions>
</x-page-header>

<div class="space-y-6">
    @can('bank-master.add')
        <x-card title="Add a branch">
            <form method="POST" action="{{ route('banks.branches.store', $bank) }}" class="grid items-start gap-4 sm:grid-cols-[10rem_1fr_auto]">
                @csrf
                <x-input name="code" label="Branch code" placeholder="e.g. 001" required />
                <x-input name="name" label="Branch name" placeholder="e.g. Colombo Main" required />
                <div class="sm:pt-[1.625rem]"><x-button type="submit">Add branch</x-button></div>
            </form>
        </x-card>
    @endcan

    <x-card :title="'Branches ('.$branches->count().')'" :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">Code</th><th class="px-5 py-3 font-medium">Branch</th><th class="px-5 py-3 font-medium">Accounts</th><th class="px-5 py-3 text-right font-medium">Actions</th></tr>
            </x-slot:head>
            @forelse ($branches as $br)
                <tr>
                    <td class="px-5 py-3 font-mono">{{ $br->code }}</td>
                    <td class="px-5 py-3 font-medium text-slate-900">{{ $br->name }}</td>
                    <td class="px-5 py-3">{{ $br->accounts_count }}</td>
                    <td class="px-5 py-3 text-right">
                        @can('bank-master.remove')
                            @if ($br->accounts_count === 0)
                                <x-confirm-action :action="route('banks.branches.destroy', [$bank, $br])" method="DELETE" :title="'Remove '.$br->name.'?'" confirm="Remove branch">
                                    <x-slot:trigger><x-button variant="ghost" size="sm"><span class="text-red-600">Remove</span></x-button></x-slot:trigger>
                                    Branches cannot be edited. Add it again if you need it back.
                                </x-confirm-action>
                            @else
                                <span class="px-2 py-1 text-xs text-slate-400" title="Used by employee accounts">In use</span>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">No branches yet.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
@endsection
