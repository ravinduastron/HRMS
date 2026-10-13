{{-- Searchable dropdown. Submits like a normal field (hidden input named $name).
     Static:   <x-combobox name="department" label="Department" :options="[['value' => 1, 'label' => 'Finance']]" :value="request('department')" empty="All departments" />
     Bound:    <x-combobox name="bank_id" x-model="bank" @change="branch = ''" :options="$bankOptions" />   (x-model binds to the PARENT's Alpine variable)
     Dynamic:  options-js="(branches[bank] ?? []).map(b => ({ value: b.id, label: b.name }))"  (re-evaluated when its variables change)
               disabled-js="!bank"
     The search box shows only when there are at least min-search (default 8) options. Use single quotes inside the JS props. --}}
@props(['name' => null, 'label' => null, 'options' => [], 'value' => '', 'empty' => null, 'placeholder' => 'Select…',
    'optionsJs' => null, 'disabledJs' => null, 'minSearch' => 8, 'hint' => null])
@php
    $id = $attributes->get('id', $name ?: 'combo_'.\Illuminate\Support\Str::random(6));
    $error = $name ? $errors->first($name) : null;
    $items = collect($options)->map(fn ($o) => ['value' => (string) $o['value'], 'label' => (string) $o['label']])->values()->all();
    $cfg = ['value' => (string) $value, 'items' => $items, 'empty' => $empty, 'placeholder' => $placeholder, 'minSearch' => (int) $minSearch];
    $effect = collect([$optionsJs ? 'items = '.$optionsJs : null, $disabledJs ? 'disabled = !!('.$disabledJs.')' : null])->filter()->implode('; ');
@endphp
<div class="space-y-1.5">
    @if ($label)<label for="{{ $id }}" class="block text-sm font-medium text-slate-700">{{ $label }}</label>@endif
    <div x-data="combo(@js($cfg))" x-modelable="value"
        @if ($effect) x-effect="{{ $effect }}" @endif
        @click.outside="close()"
        @keydown.arrow-down.prevent="open ? move(1) : show()"
        @keydown.arrow-up.prevent="open ? move(-1) : show()"
        @keydown.enter="if (open) { $event.preventDefault(); choose() }"
        @keydown.escape="if (open) { $event.stopPropagation(); close(); $refs.btn.focus() }"
        {{ $attributes->except('id')->class('relative') }}>
        @if ($name)<input type="hidden" name="{{ $name }}" :value="value">@endif

        <button type="button" id="{{ $id }}" x-ref="btn" @click="toggle()" :disabled="disabled" aria-haspopup="listbox" :aria-expanded="open"
            @class([
                'block w-full rounded-md border bg-white py-2 pl-3 pr-9 text-left text-sm shadow-xs focus:outline-2 focus:-outline-offset-1 disabled:bg-slate-50 disabled:text-slate-400',
                'border-slate-300 focus:outline-brand-600' => ! $error,
                'border-red-400 focus:outline-red-500' => $error,
            ])>
            <span class="block truncate" :class="label === null ? 'text-slate-400' : 'text-slate-900'" x-text="label ?? placeholder"></span>
            <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400"><x-icon name="chevron-down" class="size-4" /></span>
        </button>

        <div x-show="open" x-cloak x-transition.opacity.duration.100ms class="absolute z-50 mt-1 w-full rounded-md bg-white shadow-lg ring-1 ring-slate-900/10">
            <div x-show="searchable" class="border-b border-slate-100 p-2">
                <input type="text" x-ref="search" x-model="q" @input.stop="hi = 0" @change.stop @keydown.enter.prevent autocomplete="off" placeholder="Search…" aria-label="Search options"
                    class="block w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600">
            </div>
            <ul x-ref="list" role="listbox" class="max-h-60 overflow-auto py-1 text-sm">
                <template x-for="(item, i) in list" :key="item.value">
                    <li role="option" :aria-selected="String(item.value) === String(value)" @click="pick(item)" @mouseenter="hi = i" x-text="item.label"
                        :class="[i === hi ? 'bg-brand-50 text-brand-900' : 'text-slate-700', String(item.value) === String(value) ? 'font-medium' : '']"
                        class="cursor-pointer truncate px-3 py-1.5"></li>
                </template>
            </ul>
            <p x-show="list.length === 0" x-cloak class="px-3 py-2 text-sm text-slate-500">No matches</p>
        </div>
    </div>
    @if ($error)
        <p class="text-sm text-red-600">{{ $error }}</p>
    @elseif ($hint)
        <p class="text-sm text-slate-500">{{ $hint }}</p>
    @endif
</div>
