{{-- Vars: $group (new or existing). Existing groups with components cannot change type. --}}
@php
    $creating = ! $group->exists;
    $typeLocked = ! $creating && ($group->components_count ?? 0) > 0;
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600 disabled:bg-slate-50 disabled:text-slate-500';
    $combine = old('_f') ? (bool) old('combine_on_payslip') : (bool) $group->combine_on_payslip;
@endphp
<form method="POST" action="{{ $creating ? route('pay-component-groups.store') : route('pay-component-groups.update', $group) }}" class="grid gap-5 sm:grid-cols-2">
    @csrf
    @unless ($creating) @method('PUT') @endunless
    <input type="hidden" name="_f" value="1">

    <x-input name="name" label="Name" :value="old('name', $group->name)" required placeholder="e.g. Overtime" hint="Printed on the payslip when the group is shown as one line." />
    <div class="space-y-1.5">
        <label for="type" class="block text-sm font-medium text-slate-700">Type</label>
        <select id="type" name="type" class="{{ $field }}" @disabled($typeLocked) required>
            @foreach (\App\Models\PayComponent::TYPES as $k => $t)<option value="{{ $k }}" @selected(old('type', $group->type) === $k)>{{ $t }}</option>@endforeach
        </select>
        @if ($typeLocked)<p class="text-sm text-slate-500">The type cannot change while the group has components.</p>@endif
        @error('type')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
    <x-input name="payslip_order" type="number" label="Payslip order" :value="old('payslip_order', $group->payslip_order ?? 100)" required hint="Lower numbers appear first." />
    <x-input name="description" label="Description" :value="old('description', $group->description)" />
    <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
        <input type="checkbox" name="combine_on_payslip" value="1" class="size-4 accent-brand-700" @checked($combine)>
        Show as one line on the payslip (the sum of its components, amount only)
    </label>
    <div class="flex justify-end gap-2 sm:col-span-2">
        @unless ($creating)<x-button :href="route('pay-component-groups.index')" variant="secondary">Cancel</x-button>@endunless
        <x-button type="submit">{{ $creating ? 'Add group' : 'Save changes' }}</x-button>
    </div>
</form>
