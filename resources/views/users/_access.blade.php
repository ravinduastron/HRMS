@php
    $catalogue = config('permissions');
    $userRoles = $user->roles->sortBy('name')->values();
@endphp
<x-card title="Roles & permissions">
    @if ($userRoles->isEmpty())
        <p class="text-sm text-slate-500">No roles assigned.</p>
    @else
        <p class="mb-4 text-sm text-slate-500">Select a role to see the permissions it grants.</p>
        <div x-data="{ active: {{ $userRoles->first()->id }} }" class="grid gap-6 md:grid-cols-3">
            <div class="space-y-1" role="tablist">
                @foreach ($userRoles as $r)
                    <button type="button" role="tab" @click="active = {{ $r->id }}"
                        :class="active === {{ $r->id }} ? 'bg-brand-50 text-brand-800 ring-brand-200' : 'text-slate-600 ring-transparent hover:bg-slate-50'"
                        class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm ring-1 ring-inset">
                        <span class="truncate">{{ $r->name }}</span>
                        <span class="text-xs text-slate-400">{{ $r->permissions->count() }}</span>
                    </button>
                @endforeach
            </div>
            <div class="md:col-span-2">
                @foreach ($userRoles as $r)
                    @php $granted = $r->permissions->pluck('name')->all(); @endphp
                    <div x-show="active === {{ $r->id }}" @unless ($loop->first) x-cloak @endunless role="tabpanel">
                        <div class="mb-3 flex items-center gap-2">
                            <h3 class="text-sm font-semibold text-slate-900">{{ $r->name }}</h3>
                            <x-badge :color="$r->is_active ? 'green' : 'slate'">{{ $r->is_active ? 'Active' : 'Inactive' }}</x-badge>
                        </div>
                        @if ($r->description)<p class="mb-3 text-sm text-slate-500">{{ $r->description }}</p>@endif
                        @unless ($r->is_active)
                            <x-alert type="warning" class="mb-3">This role is inactive, so these permissions are not currently in effect.</x-alert>
                        @endunless
                        @php $shown = 0; @endphp
                        @foreach ($catalogue as $group => $perms)
                            @php $have = collect($perms)->only($granted); @endphp
                            @continue($have->isEmpty())
                            @php $shown++; @endphp
                            <div class="mb-4">
                                <p class="mb-1.5 text-xs font-medium text-slate-500">{{ $group }}</p>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($have as $label)<x-badge color="brand">{{ $label }}</x-badge>@endforeach
                                </div>
                            </div>
                        @endforeach
                        @if ($shown === 0)<p class="text-sm text-slate-500">This role has no permissions.</p>@endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</x-card>
