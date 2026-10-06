{{-- Open from anywhere: <button @click="$dispatch('open-modal', 'confirm-run')"> --}}
@props(['name', 'title' => null, 'maxWidth' => 'max-w-lg'])
<div x-data="{ show: false }" x-cloak x-show="show" role="dialog" aria-modal="true"
    @open-modal.window="if ($event.detail === '{{ $name }}') show = true"
    @close-modal.window="show = false" @keydown.escape.window="show = false"
    class="fixed inset-0 z-[60] grid place-items-center p-4">
    <div x-show="show" x-transition.opacity @click="show = false" class="absolute inset-0 bg-slate-900/50"></div>
    <div x-show="show" x-transition.scale.95 class="relative w-full {{ $maxWidth }} rounded-xl bg-white shadow-xl ring-1 ring-slate-900/10">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
            <button type="button" @click="show = false" class="rounded p-1 text-slate-400 hover:text-slate-600" aria-label="Close"><x-icon name="close" /></button>
        </div>
        <div class="px-5 py-4 text-sm text-slate-700">{{ $slot }}</div>
        @isset($footer)<div class="flex justify-end gap-2 rounded-b-xl border-t border-slate-200 bg-slate-50 px-5 py-3">{{ $footer }}</div>@endisset
    </div>
</div>
