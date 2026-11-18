@extends('layouts.app')
@section('title', 'New salary adjustment')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $label = 'block text-sm font-medium text-slate-700';
@endphp
<x-page-header title="New salary adjustment" description="Choose the scheme and the date the change takes effect, then the employees, the components and the values. You review the old and new amounts before anything changes.">
    <x-slot:actions>
        <x-button :href="route('salary-adjustments.index')" variant="secondary">All adjustments</x-button>
    </x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card>
        <form method="GET" action="{{ route('salary-adjustments.create') }}" class="flex flex-wrap items-end gap-3">
            <div class="w-64"><x-combobox name="scheme" label="Scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="$scheme?->id" /></div>
            <x-input name="date" type="date" label="Effective from" :value="$date->toDateString()" required />
            <x-button type="submit" variant="secondary">Show employees</x-button>
        </form>
        <p class="mt-3 text-sm text-slate-500">The amounts are taken from each employee's salary on the effective date. A date in the past is allowed; arrears are not worked out here, they belong to the pay run.</p>
    </x-card>

    @if (! $scheme)
        <x-alert type="warning">No schemes are allocated to you.</x-alert>
    @elseif ($cfg)
        @if ($hidden > 0)<x-alert type="info">{{ $hidden }} payroll employee(s) of this scheme have no amount-carrying component on their salary on {{ $date->format('d M Y') }}, so they are not listed.</x-alert>@endif

        <form method="POST" action="{{ route('salary-adjustments.store') }}" x-data="adjustmentEntry(@js($cfg))" class="space-y-6">
            @csrf
            <input type="hidden" name="scheme" value="{{ $scheme->id }}"><input type="hidden" name="effective_from" value="{{ $date->toDateString() }}">
            <input type="hidden" name="layout" :value="layout">
            <template x-for="r in sameRows" :key="r.id"><input type="hidden" name="employee_ids[]" :value="r.id"></template>
            <input type="hidden" name="cells_json" :value="cellsJson" :disabled="layout !== 'each'">

            <x-card title="What to change">
                <div class="grid gap-5 sm:grid-cols-3">
                    <div class="space-y-1.5 sm:col-span-3">
                        <label for="reason" class="{{ $label }}">Reason</label>
                        <input id="reason" name="reason" type="text" maxlength="500" required autocomplete="off" value="{{ old('reason') }}" placeholder="e.g. Annual increment 2026" class="{{ $field }}">
                        @error('reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div class="sm:col-span-3">
                        <p class="{{ $label }}">Values</p>
                        <div class="mt-1.5 flex flex-wrap gap-3">
                            <label class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm"><input type="radio" value="same" x-model="layout" class="size-4 accent-brand-700"> The same value for all selected employees</label>
                            <label class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm"><input type="radio" value="each" x-model="layout" class="size-4 accent-brand-700"> A different value for each employee</label>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="mode" class="{{ $label }}">How</label>
                        <select id="mode" name="mode" x-model="mode" class="{{ $field }}">
                            @foreach (\App\Payroll\SalaryAdjustment::MODES as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach
                        </select>
                    </div>
                    <div class="space-y-1.5 sm:col-span-2" x-show="layout === 'same'">
                        <label for="value" class="{{ $label }}" x-text="valueLabel"></label>
                        <input id="value" name="value" type="text" inputmode="decimal" autocomplete="off" :required="layout === 'same'" :disabled="layout !== 'same'" x-model="value" class="{{ $field }} max-w-xs font-mono" :class="value !== '' && !valueOk ? 'border-red-400' : ''">
                        <p class="text-sm text-slate-500" x-text="valueHint"></p>
                        @error('value')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2" x-show="layout === 'each'" x-cloak>
                        <p class="pt-7 text-sm text-slate-500">One way for the whole screen. Type each employee's value in the table below. An empty box means no change.</p>
                    </div>
                </div>
                <div class="mt-5">
                    <p class="{{ $label }}">Components</p>
                    <div class="mt-1.5 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <template x-for="c in cfg.components" :key="c.id">
                            <label class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm">
                                <input type="checkbox" name="component_ids[]" :value="c.id" x-model="comps" class="size-4 accent-brand-700">
                                <span class="font-mono text-xs" x-text="c.code"></span> <span class="text-slate-500" x-text="c.name"></span>
                            </label>
                        </template>
                    </div>
                    @error('component_ids')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-slate-500">Only components that carry an amount (fixed or variable) can be adjusted. The same mode applies to every value on this screen. To mix modes, use the Excel sheet.</p>
                </div>
            </x-card>

            {{-- Per-employee only: fill many cells at once and show the current amounts --}}
            <div x-show="layout === 'each'" x-cloak class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                <label class="text-sm font-medium text-slate-700">Value to fill in
                    <input type="text" x-model="fillValue" inputmode="decimal" autocomplete="off" class="{{ $field }} mt-1.5 w-40 font-mono" :class="fillValue !== '' && !fits(fillValue) ? 'border-red-400' : ''">
                </label>
                <label class="text-sm font-medium text-slate-700">In column
                    <select x-model="fillComp" class="{{ $field }} mt-1.5 w-48">
                        <option value="">All chosen columns</option>
                        <template x-for="c in chosen" :key="c.id"><option :value="c.id" x-text="c.code"></option></template>
                    </select>
                </label>
                <x-button variant="secondary" @click="fill(picked)" x-bind:disabled="!fillOk || picked.length === 0">Fill selected (<span x-text="picked.length"></span>)</x-button>
                <x-button variant="secondary" @click="fill(shown)" x-bind:disabled="!fillOk || shown.length === 0">Fill all shown (<span x-text="shown.length"></span>)</x-button>
                <button type="button" @click="clearAll()" x-show="filled > 0" class="pb-2 text-sm font-medium text-red-600 hover:underline">Clear all values</button>
                <div class="ml-auto flex items-center gap-2 pb-0.5" x-show="cfg.currentUrl">
                    <x-button variant="secondary" x-show="cur === null" @click="loadCurrent()" x-bind:disabled="curLoading">Show current amounts</x-button>
                    <x-button variant="secondary" x-show="cur !== null" x-cloak @click="hideCurrent()">Hide current amounts</x-button>
                </div>
                <p class="w-full text-xs text-slate-500">Filling only puts values in the boxes; you can still change each one. Nothing is saved until you press Preview, and even then you review before anything is applied.</p>
            </div>

            <x-card title="Employees" :padding="false">
                <div class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                    <input type="search" x-model="q" placeholder="Find an employee" aria-label="Find an employee" class="block w-100 rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600">
                    <select x-model="dept" aria-label="Department" class="block rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600">
                        <option value="">All departments</option>
                        <template x-for="d in departments" :key="d"><option :value="d" x-text="d"></option></template>
                    </select>
                    <p class="ml-auto pb-2 text-sm text-slate-500" x-text="layout === 'same' ? shown.length + ' shown, ' + picked.length + ' selected' : shown.length + ' shown, ' + filled + ' value(s) entered'"></p>
                </div>
                <p x-show="comps.length === 0" x-cloak class="border-b border-slate-100 px-5 py-2.5 text-sm text-amber-700">Choose at least one component to see the employees who have it.</p>
                <x-table>
                    <x-slot:head>
                        <tr>
                            <th class="w-10 px-5 py-3"><input type="checkbox" :checked="allSelected" @change="toggleShown($event.target.checked)" class="size-4 accent-brand-700" aria-label="Select all shown employees"></th>
                            <th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Department</th>
                            <template x-for="c in cols" :key="c.id"><th class="px-5 py-3 font-medium" x-text="c.code"></th></template>
                        </tr>
                    </x-slot:head>
                    <template x-for="r in shown" :key="r.id">
                        <tr>
                            <td class="px-5 py-2.5"><input type="checkbox" x-model="r.selected" class="size-4 accent-brand-700" :aria-label="'Select ' + r.name"></td>
                            <td class="px-5 py-2.5"><span class="font-medium text-slate-900" x-text="r.name"></span><p class="text-xs text-slate-500" x-text="r.no"></p></td>
                            <td class="px-5 py-2.5 text-sm text-slate-600" x-text="r.dept || '—'"></td>
                            <template x-for="c in cols" :key="c.id">
                                    <td class="px-5 py-2.5 align-top">
                                        <template x-if="has(r, c.id)">
                                            <div>
                                                <input type="text" x-model="r.v[c.id]" inputmode="decimal" autocomplete="off" :placeholder="placeholder" :aria-label="c.code + ' for ' + r.name"
                                                    :class="bad(r, c.id) ? 'border-red-400' : 'border-slate-300'"
                                                    class="block w-full min-w-28 max-w-40 rounded-md border bg-white px-3 py-2 font-mono text-sm shadow-xs focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600">
                                                <p x-show="bad(r, c.id)" x-cloak class="mt-1 text-xs text-red-600">Not a valid value.</p>
                                                <p x-show="cur !== null" x-cloak class="mt-1 font-mono text-xs text-slate-500" x-text="'Now ' + current(r, c.id)"></p>
                                            </div>
                                        </template>
                                        <span x-show="!has(r, c.id)" class="text-slate-300">—</span>
                                    </td>
                            </template>
                        </tr>
                    </template>
                    <tr x-show="comps.length > 0 && shown.length === 0" x-cloak><td colspan="12" class="px-5 py-10 text-center text-slate-500">No employee matches.</td></tr>
                </x-table>
            </x-card>
            @error('employee_ids')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @error('cells_json')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @error('rows')<p class="text-sm text-red-600">{{ $message }}</p>@enderror

            <div class="flex items-center justify-end gap-4">
                <p x-show="layout === 'each' && invalid > 0" x-cloak class="text-sm text-red-600" x-text="invalid + ' value(s) are not valid.'"></p>
                <p class="text-sm text-slate-500">Nothing changes yet: you review the result first.</p>
                <x-button type="submit" x-bind:disabled="!ready">Preview the adjustment</x-button>
            </div>
        </form>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('adjustmentEntry', (cfg) => ({
                    cfg: cfg,
                    rows: cfg.rows.map((r) => Object.assign({}, r, { selected: false, v: {}, s: (r.no + ' ' + r.name + ' ' + r.dept).toLowerCase() })),
                    comps: [], q: '', dept: '', layout: cfg.layout, mode: cfg.mode, value: '',
                    fillValue: '', fillComp: '', cur: null, curLoading: false, curTimer: null,

                    clean(v) { return String(v == null ? '' : v).replace(/[, %]/g, '').replace(/^\+/, ''); },
                    fits(v) {
                        const s = this.clean(v);
                        if (this.mode === 'amount') return /^-?\d{1,13}(\.\d{1,2})?$/.test(s);
                        if (this.mode === 'percent') return /^-?\d{1,3}(\.\d{1,4})?$/.test(s) && Number(s) >= -100 && Number(s) <= 1000;
                        return /^\d{1,13}(\.\d{1,2})?$/.test(s);
                    },
                    get valueLabel() { return { amount: 'Amount to add (Rs)', percent: 'Percentage to add (%)', new: 'New salary (Rs)' }[this.mode]; },
                    get valueHint() {
                        return { amount: 'For example 2500. Use a minus sign to reduce: -1000.',
                            percent: 'For example 10 for +10%. Use a minus sign to reduce: -5 for 5% less.',
                            new: 'The amount becomes exactly this (zero or more).' }[this.mode];
                    },
                    get placeholder() { return { amount: '+ / - Rs', percent: '+ / - %', new: 'New Rs' }[this.mode]; },
                    get valueOk() { return this.fits(this.value); },

                    get departments() { return Array.from(new Set(this.rows.map((r) => r.dept).filter(Boolean))).sort(); },
                    get cols() { return this.layout === 'each' ? this.chosen : []; },
                    get sameRows() { return this.layout === 'same' ? this.picked : []; },
                    get chosen() { return this.cfg.components.filter((c) => this.comps.includes(c.id)); },
                    get shown() {
                        const q = this.q.trim().toLowerCase();
                        return this.rows.filter((r) => r.comps.some((c) => this.comps.includes(c)) && (!q || r.s.includes(q)) && (!this.dept || r.dept === this.dept));
                    },
                    get picked() { return this.shown.filter((r) => r.selected); },
                    get allSelected() { const s = this.shown; return s.length > 0 && s.every((r) => r.selected); },
                    toggleShown(on) { this.shown.forEach((r) => { r.selected = on; }); },

                    /* per-employee cells */
                    has(r, c) { return r.comps.includes(c); },
                    blank(v) { return v === undefined || v === null || String(v).trim() === ''; },
                    bad(r, c) { return !this.blank(r.v[c]) && !this.fits(r.v[c]); },
                    each(fn) { this.rows.forEach((r) => this.comps.forEach((c) => { if (this.has(r, c)) fn(r, c); })); },
                    get filled() { let n = 0; this.each((r, c) => { if (!this.blank(r.v[c])) n++; }); return n; },
                    get invalid() { let n = 0; this.each((r, c) => { if (this.bad(r, c)) n++; }); return n; },
                    get cellsJson() {
                        const o = {};
                        this.each((r, c) => {
                            if (this.blank(r.v[c])) return;
                            if (!o[r.id]) o[r.id] = {};
                            o[r.id][c] = String(r.v[c]).trim();
                        });
                        return JSON.stringify(o);
                    },
                    get fillOk() { return this.fits(this.fillValue) && this.chosen.length > 0; },
                    fill(targets) {
                        if (!this.fillOk) return;
                        const v = String(this.fillValue).trim();
                        const cols = this.fillComp && this.comps.includes(this.fillComp) ? [this.fillComp] : this.comps;
                        targets.forEach((r) => cols.forEach((c) => { if (this.has(r, c)) r.v[c] = v; }));
                    },
                    clearAll() { this.rows.forEach((r) => { r.v = {}; }); },

                    get ready() {
                        if (this.layout === 'same') return this.comps.length > 0 && this.picked.length > 0 && this.valueOk;
                        return this.filled > 0 && this.invalid === 0;
                    },

                    /* current amounts: fetched on demand (audited), hidden again after a while */
                    fmt(v) { const p = String(v).split('.'); return p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (p[1] || '').padEnd(2, '0'); },
                    current(r, c) { const a = this.cur && this.cur[r.id] ? this.cur[r.id][c] : undefined; return a === undefined ? '—' : this.fmt(a); },
                    async loadCurrent() {
                        this.curLoading = true;
                        try {
                            const res = await fetch(this.cfg.currentUrl, { headers: { Accept: 'application/json' } });
                            if (!res.ok) throw new Error();
                            const j = await res.json();
                            this.cur = j.amounts || {};
                            clearTimeout(this.curTimer);
                            this.curTimer = setTimeout(() => { this.cur = null; }, (j.seconds || 20) * 1000);
                        } catch (e) { window.toast('Could not load the current amounts.', 'error'); }
                        this.curLoading = false;
                    },
                    hideCurrent() { this.cur = null; clearTimeout(this.curTimer); },
                }));
            });
        </script>
    @endif
</div>
@endsection
