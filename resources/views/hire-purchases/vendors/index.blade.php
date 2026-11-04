@extends('layouts.app')
@section('title', 'Hire purchase vendors')

@section('content')
<x-page-header title="Hire purchase vendors" description="Suppliers employees buy from on hire purchase. A vendor is deactivated, never deleted.">
    <x-slot:actions><x-button :href="route('hire-purchases.index')" variant="secondary">All hire purchases</x-button></x-slot:actions>
</x-page-header>

<div class="space-y-6">
    @can('hire-purchase-vendors.manage')
        <x-card title="Add a vendor">@include('hire-purchases.vendors._form')</x-card>
    @endcan

    <x-card :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Vendor</th><th class="px-5 py-3 font-medium">Contact</th>
                    <th class="px-5 py-3 font-medium">Hire purchases</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3 text-right font-medium">Actions</th>
                </tr>
            </x-slot:head>
            @forelse ($vendors as $v)
                <tr>
                    <td class="px-5 py-3"><span class="font-medium text-slate-900">{{ $v->name }}</span><p class="font-mono text-xs text-slate-500">{{ $v->code }}</p></td>
                    <td class="px-5 py-3 text-sm">{{ $v->contact_person ?? '—' }}
                        <p class="text-xs text-slate-500">{{ collect([$v->phone, $v->email])->filter()->implode(' · ') }}</p>
                        @if ($v->address)<p class="text-xs text-slate-500">{{ $v->address }}</p>@endif</td>
                    <td class="px-5 py-3">{{ $v->purchases_count }}@if ($v->purchases_count)<span class="text-xs text-slate-500"> ({{ $v->active_purchases_count }} active)</span>@endif</td>
                    <td class="px-5 py-3"><x-badge :color="$v->is_active ? 'green' : 'slate'">{{ $v->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                    <td class="px-5 py-3">
                        @can('hire-purchase-vendors.manage')
                            <div class="flex justify-end gap-1">
                                <x-button :href="route('hire-purchase-vendors.edit', $v)" variant="ghost" size="sm">Edit</x-button>
                                <x-confirm-action :action="route('hire-purchase-vendors.toggle', $v)" method="PATCH" :title="($v->is_active ? 'Deactivate ' : 'Activate ').$v->name.'?'"
                                    :confirm="$v->is_active ? 'Deactivate' : 'Activate'" :variant="$v->is_active ? 'danger' : 'primary'">
                                    <x-slot:trigger><x-button variant="ghost" size="sm">{{ $v->is_active ? 'Deactivate' : 'Activate' }}</x-button></x-slot:trigger>
                                    {{ $v->is_active ? 'No new hire purchase can use this vendor. Existing ones are not affected.' : 'New hire purchases can use this vendor again.' }}
                                </x-confirm-action>
                            </div>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No vendors yet.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
@endsection
