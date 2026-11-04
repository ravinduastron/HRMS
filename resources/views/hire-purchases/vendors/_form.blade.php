{{-- Vars: $vendor (new or existing). The code cannot change once created. --}}
@php $creating = ! $vendor->exists; @endphp
<form method="POST" action="{{ $creating ? route('hire-purchase-vendors.store') : route('hire-purchase-vendors.update', $vendor) }}" class="grid gap-5 sm:grid-cols-2">
    @csrf
    @unless ($creating) @method('PUT') @endunless

    @if ($creating)
        <x-input name="code" label="Code" :value="old('code')" required autocomplete="off" placeholder="e.g. SINGER" hint="Letters, digits, dash and underscore. It cannot be changed later." />
    @else
        <div class="space-y-1.5"><p class="block text-sm font-medium text-slate-700">Code</p><p class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-sm text-slate-700">{{ $vendor->code }}</p></div>
    @endif
    <x-input name="name" label="Vendor name" :value="old('name', $vendor->name)" required />
    <x-input name="contact_person" label="Contact person" :value="old('contact_person', $vendor->contact_person)" />
    <x-input name="phone" label="Phone" :value="old('phone', $vendor->phone)" />
    <x-input name="email" type="email" label="Email" :value="old('email', $vendor->email)" />
    <x-input name="address" label="Address" :value="old('address', $vendor->address)" />
    <div class="flex justify-end gap-2 sm:col-span-2">
        @unless ($creating)<x-button :href="route('hire-purchase-vendors.index')" variant="secondary">Cancel</x-button>@endunless
        <x-button type="submit">{{ $creating ? 'Add vendor' : 'Save changes' }}</x-button>
    </div>
</form>
