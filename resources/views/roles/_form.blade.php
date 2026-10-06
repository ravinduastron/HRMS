@php
    $creating = ! $role->exists;
    $selected = old('permissions', $creating ? [] : $role->permissions->pluck('name')->all());
@endphp
<form method="POST" action="{{ $creating ? route('roles.store') : route('roles.update', $role) }}" x-data="{ selected: @js($selected), loading: false }" @submit="loading = true" class="space-y-6">
    @csrf
    @unless ($creating) @method('PUT') @endunless

    <x-card title="Role details">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-input name="name" label="Role name" :value="old('name', $role->name)" required />
            <x-input name="description" label="Description" :value="old('description', $role->description)" />
        </div>
    </x-card>

    <x-card title="Permissions">
        <div class="space-y-6">
            @foreach (config('permissions') as $group => $perms)
                <fieldset x-data="{ names: @js(array_keys($perms)) }">
                    <div class="mb-2 flex items-center justify-between">
                        <legend class="text-sm font-semibold text-slate-900">{{ $group }}</legend>
                        <button type="button" class="text-xs font-medium text-brand-700 hover:underline"
                            @click="names.every(n => selected.includes(n)) ? selected = selected.filter(s => !names.includes(s)) : selected = [...new Set([...selected, ...names])]">
                            Select / clear all
                        </button>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($perms as $name => $label)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded-md border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                                <input type="checkbox" name="permissions[]" value="{{ $name }}" x-model="selected" class="size-4 accent-brand-700">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        </div>
        @error('permissions.*')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    </x-card>

    <div class="flex justify-end gap-2">
        <x-button :href="route('roles.index')" variant="secondary">Cancel</x-button>
        <x-button type="submit" loading="loading">{{ $creating ? 'Create role' : 'Save changes' }}</x-button>
    </div>
</form>
