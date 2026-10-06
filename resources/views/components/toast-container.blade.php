{{-- Place once in each layout. Shows Laravel's `status` flash as a success toast and renders window.toast() calls. --}}
<div x-data
    @if (session('status')) x-init="$store.toasts.add({ message: @js(session('status')), type: 'success' })" @endif
    class="pointer-events-none fixed inset-x-0 top-16 z-[70] flex flex-col items-end gap-2 px-4 sm:px-6" aria-live="polite">
    <template x-for="t in $store.toasts.items" :key="t.id">
        <div x-data="{ show: false }" x-init="$nextTick(() => show = true)" x-show="show && !t.leaving"
            x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-x-4 opacity-0" x-transition:enter-end="translate-x-0 opacity-100"
            x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            @mouseenter="$store.toasts.cancel(t.id)" @mouseleave="t.type !== 'error' && t.type !== 'warning' && $store.toasts.schedule(t.id, 2000)"
            :role="t.type === 'error' ? 'alert' : 'status'"
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg bg-white p-3.5 shadow-lg ring-1 ring-slate-900/10">
            <span class="mt-0.5 shrink-0"
                :class="{ 'text-emerald-500': t.type === 'success', 'text-sky-500': t.type === 'info', 'text-amber-500': t.type === 'warning', 'text-red-500': t.type === 'error' }">
                <x-icon name="check" x-show="t.type === 'success'" />
                <x-icon name="info" x-show="t.type === 'info'" x-cloak />
                <x-icon name="warning" x-show="t.type === 'warning' || t.type === 'error'" x-cloak />
            </span>
            <div class="min-w-0 flex-1 text-sm">
                <p x-show="t.title" x-text="t.title" class="font-medium text-slate-900"></p>
                <p x-text="t.message" class="text-slate-600" :class="t.title ? '' : 'text-slate-800'"></p>
            </div>
            <button type="button" @click="$store.toasts.remove(t.id)" class="-m-1 shrink-0 rounded p-1 text-slate-400 hover:text-slate-600" aria-label="Dismiss">
                <x-icon name="close" class="size-4" />
            </button>
        </div>
    </template>
</div>
