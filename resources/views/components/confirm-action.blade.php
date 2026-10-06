{{-- <x-confirm-action :action="route(...)" method="PATCH" title="Deactivate user?" confirm="Deactivate">
       <x-slot:trigger><x-button variant="secondary">Deactivate</x-button></x-slot:trigger> Message… </x-confirm-action> --}}
@props(['action', 'method' => 'POST', 'title', 'confirm' => 'Confirm', 'variant' => 'danger'])
<div x-data="{ open: false }" class="inline-block">
    <span @click="open = true">{{ $trigger }}</span>
    <template x-teleport="body">
        <div x-show="open" x-cloak @keydown.escape.window="open = false" role="dialog" aria-modal="true"
            class="fixed inset-0 z-[60] grid place-items-center p-4">
            <div class="absolute inset-0 bg-slate-900/50" @click="open = false"></div>
            <form method="POST" action="{{ $action }}" class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl ring-1 ring-slate-900/10">
                @csrf
                @if (strtoupper($method) !== 'POST') @method($method) @endif
                <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
                <div class="mt-2 text-sm text-slate-600">{{ $slot }}</div>
                <div class="mt-5 flex justify-end gap-2">
                    <x-button variant="secondary" @click="open = false">Cancel</x-button>
                    <x-button type="submit" :variant="$variant">{{ $confirm }}</x-button>
                </div>
            </form>
        </div>
    </template>
</div>
