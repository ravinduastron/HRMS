{{-- <x-confirm-action :action="route(...)" method="PATCH" title="Deactivate user?" confirm="Deactivate">
       <x-slot:trigger><x-button variant="secondary">Deactivate</x-button></x-slot:trigger> Message… </x-confirm-action>
     High-risk actions: add phrase="APPROVE" to make the user type it before the confirm button enables. --}}
@props(['action', 'method' => 'POST', 'title', 'confirm' => 'Confirm', 'variant' => 'danger', 'phrase' => null])
@php
    // Alpine expression: with a phrase, the confirm button stays disabled until it is typed exactly.
    $disabledWhen = $phrase ? "typed !== '".addslashes($phrase)."'" : 'false';
@endphp
<div x-data="{ open: false, typed: '' }" class="inline-block">
    <span @click="open = true">{{ $trigger }}</span>
    <template x-teleport="body">
        <div x-show="open" x-cloak @keydown.escape.window="open = false; typed = ''" role="dialog" aria-modal="true"
            class="fixed inset-0 z-[60] grid place-items-center p-4">
            <div class="absolute inset-0 bg-slate-900/50" @click="open = false; typed = ''"></div>
            <form method="POST" action="{{ $action }}" class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl ring-1 ring-slate-900/10">
                @csrf
                @if (strtoupper($method) !== 'POST') @method($method) @endif
                <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
                <div class="mt-2 text-sm text-slate-600">{{ $slot }}</div>
                @if ($phrase)
                    <label class="mt-4 block text-sm text-slate-700">Type <strong class="font-semibold">{{ $phrase }}</strong> to continue
                        <input type="text" x-model="typed" autocomplete="off"
                            class="mt-1.5 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600">
                    </label>
                @endif
                <div class="mt-5 flex justify-end gap-2">
                    <x-button variant="secondary" @click="open = false; typed = ''">Cancel</x-button>
                    <x-button type="submit" :variant="$variant" x-bind:disabled="{{ $disabledWhen }}">{{ $confirm }}</x-button>
                </div>
            </form>
        </div>
    </template>
</div>
