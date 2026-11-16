@extends('layouts.app')
@section('title', 'New salary adjustment')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $label = 'block text-sm font-medium text-slate-700';
@endphp
<x-page-header title="New salary adjustment" description="Choose the scheme and the date the change takes effect, then the employees, the components and the value. You review the old and new amounts before anything changes.">
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
            <template x-for="r in picked" :key="r.id"><input type="hidden" name="employee_ids[]" :value="r.id"></template>

            <x-card title="What to change">
                <div class="grid gap-5 sm:grid-cols-3">
                    <div class="space-y-1.5 sm:col-span-3">
                        <label for="reason" class="{{ $label }}">Reason</label>
                        <input id="reason" name="reason" type="text" maxlength="500" required autocomplete="off" value="{{ old('reason') }}" placeholder="e.g. Annual increment 2026" class="{{ $field }}">
                        @error('reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="space-y-1.5">
                        <label for="mode" class="{{ $label }}">How</label>
                        <select id="mode" name="mode" x-model="mode" class="{{ $field }}">
                            @foreach (\App\Payroll\SalaryAdjustment::MODES as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach
                        </select>
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <label for="value" class="{{ $label }}" x-text="valueLabel"></label>
                        <input id="value" name="value" type="text" inputmode="decimal" autocomplete="off" required x-model="value" class="{{ $field }} max-w-xs font-mono" :class="value !== '' && !valueOk ? 'border-red-400' : ''">
                        <p class="text-sm text-slate-500" x-text="valueHint"></p>
                        @error('value')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
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
                    <p class="mt-2 text-xs text-slate-500">Only components that carry an amount (fixed or variable) can be adjusted. The same mode and value apply to every chosen component. To mix modes, use the Excel sheet.</p>
                </div>
            </x-card>

            <x-card title="Employees" :padding="false">
                <div class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                    <input type="search" x-model="q" placeholder="Find an employee" aria-label="Find an employee" class="{{ $field }} w-56">
                    <select x-model="dept" aria-label="Department" class="{{ $field }} w-56">
                        <option value="">All departments</option>
                        <template x-for="d in departments" :key="d"><option :value="d" x-text="d"></option></template>
                    </select>
                    <p class="ml-auto pb-2 text-sm text-slate-500" x-text="shown.length + ' shown, ' + picked.length + ' selected'"></p>
                </div>
                <p x-show="comps.length === 0" x-cloak class="border-b border-slate-100 px-5 py-2.5 text-sm text-amber-700">Choose at least one component to see the employees who have it.</p>
                <x-table>
                    <x-slot:head>
                        <tr>
                            <th class="w-10 px-5 py-3"><input type="checkbox" :checked="allSelected" @change="toggleShown($event.target.checked)" class="size-4 accent-brand-700" aria-label="Select all shown employees"></th>
                            <th class="px-5 py-3 font-medium">Employee</th><th class="px-5 py-3 font-medium">Department</th>
                        </tr>
                    </x-slot:head>
                    <template x-for="r in shown" :key="r.id">
                        <tr>
                            <td class="px-5 py-2.5"><input type="checkbox" x-model="r.selected" class="size-4 accent-brand-700" :aria-label="'Select ' + r.name"></td>
                            <td class="px-5 py-2.5"><span class="font-medium text-slate-900" x-text="r.name"></span><p class="text-xs text-slate-500" x-text="r.no"></p></td>
                            <td class="px-5 py-2.5 text-sm text-slate-600" x-text="r.dept || '—'"></td>
                        </tr>
                    </template>
                    <tr x-show="comps.length > 0 && shown.length === 0" x-cloak><td colspan="3" class="px-5 py-10 text-center text-slate-500">No employee matches.</td></tr>
                </x-table>
            </x-card>
            @error('employee_ids')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @error('rows')<p class="text-sm text-red-600">{{ $message }}</p>@enderror

            <div class="flex items-center justify-end gap-4">
                <p class="text-sm text-slate-500">Nothing changes yet: you review the result first.</p>
                <x-button type="submit" x-bind:disabled="!ready">Preview the adjustment</x-button>
            </div>
        </form>

        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('adjustmentEntry', (cfg) => ({
                    cfg: cfg,
                    rows: cfg.rows.map((r) => Object.assign({}, r, { selected: false, s: (r.no + ' ' + r.name + ' ' + r.dept).toLowerCase() })),
                    comps: [], q: '', dept: '', mode: 'amount', value: '',

                    get valueLabel() { return { amount: 'Amount to add (Rs)', percent: 'Percentage to add (%)', new: 'New salary (Rs)' }[this.mode]; },
                    get valueHint() {
                        return { amount: 'For example 2500. Use a minus sign to reduce: -1000.',
                            percent: 'For example 10 for +10%. Use a minus sign to reduce: -5 for 5% less.',
                            new: 'The amount becomes exactly this (zero or more).' }[this.mode];
                    },
                    get valueOk() {
                        const s = String(this.value).replace(/[, %]/g, '').replace(/^\+/, '');
                        if (this.mode === 'amount') return /^-?\d{1,13}(\.\d{1,2})?$/.test(s);
                        if (this.mode === 'percent') return /^-?\d{1,3}(\.\d{1,4})?$/.test(s) && Number(s) >= -100 && Number(s) <= 1000;
                        return /^\d{1,13}(\.\d{1,2})?$/.test(s);
                    },
                    get departments() { return Array.from(new Set(this.rows.map((r) => r.dept).filter(Boolean))).sort(); },
                    get shown() {
                        const q = this.q.trim().toLowerCase();
                        return this.rows.filter((r) => r.comps.some((c) => this.comps.includes(c)) && (!q || r.s.includes(q)) && (!this.dept || r.dept === this.dept));
                    },
                    get picked() { return this.shown.filter((r) => r.selected); },
                    get allSelected() { const s = this.shown; return s.length > 0 && s.every((r) => r.selected); },
                    toggleShown(on) { this.shown.forEach((r) => { r.selected = on; }); },
                    get ready() { return this.comps.length > 0 && this.picked.length > 0 && this.valueOk; },
                }));
            });
        </script>
    @endif
</div>
@endsection
