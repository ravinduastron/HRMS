@extends('layouts.app')
@section('title', 'Enter pay inputs')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $open = $period?->isOpen();
    $variable = $payComponent?->latestVersion()?->calc_method === 'variable';
@endphp
<x-page-header title="Enter pay inputs" description="Enter one component for the employees of a pay period. An amount replaces the value in force (the old one stays in the history). Leave a box empty to keep it as it is.">
    <x-slot:actions>@if ($period)<x-button :href="route('pay-inputs.index', ['scheme' => $period->scheme_id, 'period' => $period->id])" variant="secondary">Back to inputs</x-button>@endif</x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card>@include('payroll.inputs._picker', ['action' => route('pay-inputs.entry')])</x-card>

    @if ($period)
        @unless ($open)
            <x-alert type="info">This pay period is {{ strtolower(\App\Models\PayPeriod::STATUSES[$period->status]) }}, so inputs cannot be entered.</x-alert>
        @else
            <x-card>
                <form method="GET" action="{{ route('pay-inputs.entry') }}" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="scheme" value="{{ $period->scheme_id }}"><input type="hidden" name="period" value="{{ $period->id }}">
                    <div class="w-96 max-w-full"><x-combobox name="component" label="Component" placeholder="Choose a component" :value="$payComponent?->id"
                        :options="$components->map(fn ($c) => ['value' => $c->id, 'label' => $c->code.' · '.$c->name])->values()" /></div>
                    <x-button type="submit" variant="secondary">Choose</x-button>
                </form>
                @if ($components->isEmpty())
                    <x-alert type="warning" class="mt-4">No component can be entered per period yet. Create a pay component whose method is "Entered each period" or "Variable amount".</x-alert>
                @endif
            </x-card>

            @if ($payComponent && $cfg)
                @if ($variable)<x-alert type="info">{{ $payComponent->code }} is a variable amount: a value entered here replaces the amount on the employee's salary for this pay period only.</x-alert>@endif
                @if ($hidden > 0)<x-alert type="info">{{ $hidden }} payroll employee(s) of this scheme do not have {{ $payComponent->code }} on their salary at the end of this period, so they are not listed. @can('pay-inputs.manage')Use "Add employees" to include them for this pay period only.@endcan</x-alert>@endif

                <div x-data="payInputEntry(@js($cfg))" class="space-y-6">
                    <form method="POST" action="{{ route('pay-inputs.entry.save') }}">
                        @csrf
                        <input type="hidden" name="period" value="{{ $period->id }}"><input type="hidden" name="component" value="{{ $payComponent->id }}">
                        <template x-for="r in dirtyRows" :key="r.id"><input type="hidden" :name="'amounts[' + r.id + ']'" :value="clean(r.amount)"></template>

                        <x-card :title="$payComponent->code.' · '.$payComponent->name" :padding="false">
                            <x-slot:actions>
                                <x-button variant="secondary" size="sm" @click="openAdd()" x-show="cfg.canAdd"><x-icon name="plus" class="size-4" /> Add employees</x-button>
                            </x-slot:actions>

                            {{-- Filters --}}
                            <div class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                                <input type="search" x-model="q" placeholder="Find an employee" aria-label="Find an employee" class="{{ $field }} w-56">
                                <select x-model="dept" aria-label="Department" class="{{ $field }} w-56">
                                    <option value="">All departments</option>
                                    <template x-for="d in departments" :key="d"><option :value="d" x-text="d"></option></template>
                                </select>
                                <label class="flex items-center gap-2 pb-2 text-sm text-slate-700">
                                    <input type="checkbox" x-model="onlyNew" class="size-4 accent-brand-700"> New employees only
                                    <span class="text-xs text-slate-500" x-text="'(' + newCount + ')'"></span>
                                </label>
                                <p class="ml-auto pb-2 text-sm text-slate-500" x-text="filtered.length + ' of ' + rows.length + ' employees shown'"></p>
                            </div>

                            {{-- Bulk update --}}
                            <div class="flex flex-wrap items-end gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
                                <label class="text-sm font-medium text-slate-700">Amount for many employees (Rs)
                                    <input type="text" x-model="bulk" inputmode="decimal" autocomplete="off" placeholder="e.g. 5000" class="{{ $field }} mt-1.5 w-48 font-mono">
                                </label>
                                <x-button variant="secondary" @click="fillSelected()" x-bind:disabled="!bulkValid || selectedShown === 0">
                                    Set for selected (<span x-text="selectedShown"></span>)
                                </x-button>
                                <x-button variant="secondary" @click="fillShown()" x-bind:disabled="!bulkValid || filtered.length === 0">
                                    Set for all shown (<span x-text="filtered.length"></span>)
                                </x-button>
                                <button type="button" @click="resetSelected()" x-show="selectedShown > 0" x-cloak class="pb-2 text-sm font-medium text-brand-700 hover:underline">Reset selected to saved values</button>
                                <p class="pb-2 text-xs text-slate-500">This only fills the boxes below. Nothing is saved until you press Save amounts.</p>
                            </div>

                            <p class="border-b border-slate-100 px-5 py-2.5 text-xs text-slate-500">
                                <span class="mr-1 inline-block size-2.5 rounded-sm bg-emerald-200 align-middle"></span>Highlighted rows are new employees: they joined after {{ \Illuminate\Support\Carbon::parse($cutoff)->format('d M Y') }}, the end of the previous pay period.
                            </p>

                            <x-table>
                                <x-slot:head>
                                    <tr>
                                        <th class="w-10 px-5 py-3"><input type="checkbox" :checked="allShownSelected" @change="toggleShown($event.target.checked)" class="size-4 accent-brand-700" aria-label="Select all shown employees"></th>
                                        <th class="px-5 py-3 font-medium">Employee</th>
                                        <th class="px-5 py-3 font-medium">Department</th>
                                        <th class="px-5 py-3 font-medium">Amount (Rs)</th>
                                        <th class="px-5 py-3"></th>
                                    </tr>
                                </x-slot:head>
                                <template x-for="r in filtered" :key="r.id">
                                    <tr :class="r.isNew ? 'bg-emerald-50' : ''">
                                        <td class="px-5 py-2.5"><input type="checkbox" x-model="r.selected" class="size-4 accent-brand-700" :aria-label="'Select ' + r.name"></td>
                                        <td class="px-5 py-2.5">
                                            <span class="font-medium text-slate-900" x-text="r.name"></span>
                                            <span x-show="r.isNew" class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-inset ring-emerald-600/20">New</span>
                                            <span x-show="r.added" class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">Added for this period</span>
                                            <p class="text-xs text-slate-500" x-text="r.no"></p>
                                        </td>
                                        <td class="px-5 py-2.5 text-sm text-slate-600" x-text="r.dept || '—'"></td>
                                        <td class="px-5 py-2.5">
                                            <input type="text" x-model="r.amount" inputmode="decimal" autocomplete="off" :aria-label="'Amount for ' + r.name"
                                                :class="bad(r) ? 'border-red-400' : 'border-slate-300'"
                                                class="block w-full max-w-52 rounded-md border bg-white px-3 py-2 font-mono text-sm shadow-xs focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600">
                                            <p x-show="bad(r)" x-cloak class="mt-1 text-xs text-red-600">Use a number with up to 2 decimals.</p>
                                        </td>
                                        <td class="px-5 py-2.5 text-right">
                                            <button type="button" x-show="r.added && !r.hasInput && cfg.canAdd" @click="removeAddition(r)" class="text-xs font-medium text-red-600 hover:underline">Take out of this period</button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="rows.length === 0" x-cloak><td colspan="5" class="px-5 py-10 text-center text-slate-500">No employee of this scheme has {{ $payComponent->code }} for this period. @can('pay-inputs.manage')Use "Add employees".@endcan</td></tr>
                                <tr x-show="rows.length > 0 && filtered.length === 0" x-cloak><td colspan="5" class="px-5 py-10 text-center text-slate-500">No employee matches the filters.</td></tr>
                            </x-table>
                        </x-card>

                        <div class="mt-4 flex items-center justify-end gap-4">
                            <p class="text-sm text-slate-500" x-show="invalidCount > 0" x-cloak><span class="text-red-600" x-text="invalidCount + ' amount(s) are not valid.'"></span></p>
                            <p class="text-sm text-slate-500" x-text="dirtyCount + ' change(s) ready to save'"></p>
                            <x-button type="submit" x-bind:disabled="dirtyCount === 0 || invalidCount > 0">Save amounts</x-button>
                        </div>
                    </form>

                    {{-- "Add employees" popup. Teleported outside the form, so nothing in it is ever submitted with the amounts. --}}
                    <template x-teleport="body">
                        <div x-show="modal.open" x-cloak @keydown.escape.window="modal.open = false" role="dialog" aria-modal="true" class="fixed inset-0 z-[60] grid place-items-center p-4">
                            <div class="absolute inset-0 bg-slate-900/50" @click="modal.open = false"></div>
                            <div class="relative flex max-h-[85vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-xl ring-1 ring-slate-900/10">
                                <div class="border-b border-slate-200 px-5 py-4">
                                    <h3 class="text-base font-semibold text-slate-900">Add employees to {{ $payComponent->code }}</h3>
                                    <p class="mt-1 text-sm text-slate-500">These employees of the scheme do not have {{ $payComponent->code }}. Adding them includes them for {{ $period->label() }} only. Their salary is not changed.</p>
                                </div>
                                <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 px-5 py-3">
                                    <input type="search" x-model="modal.q" placeholder="Find an employee" aria-label="Find an employee" class="{{ $field }} w-52">
                                    <select x-model="modal.dept" aria-label="Department" class="{{ $field }} w-52">
                                        <option value="">All departments</option>
                                        <template x-for="d in addableDepartments" :key="d"><option :value="d" x-text="d"></option></template>
                                    </select>
                                    <label class="flex items-center gap-2 pb-2 text-sm text-slate-700"><input type="checkbox" x-model="modal.onlyNew" class="size-4 accent-brand-700"> New only</label>
                                </div>
                                <div class="min-h-0 flex-1 overflow-y-auto">
                                    <label class="flex items-center gap-3 border-b border-slate-100 bg-slate-50 px-5 py-2 text-sm font-medium text-slate-700">
                                        <input type="checkbox" :checked="allModalSelected" @change="toggleModalShown($event.target.checked)" class="size-4 accent-brand-700">
                                        Select all shown (<span x-text="modalList.length"></span>)
                                    </label>
                                    <template x-for="a in modalList" :key="a.id">
                                        <label class="flex cursor-pointer items-center gap-3 border-b border-slate-100 px-5 py-2.5 text-sm hover:bg-slate-50" :class="a.isNew ? 'bg-emerald-50' : ''">
                                            <input type="checkbox" x-model="modal.picked[a.id]" class="size-4 accent-brand-700">
                                            <span class="min-w-0 flex-1">
                                                <span class="font-medium text-slate-900" x-text="a.name"></span>
                                                <span x-show="a.isNew" class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-inset ring-emerald-600/20">New</span>
                                                <span class="block text-xs text-slate-500" x-text="a.no + (a.dept ? ' · ' + a.dept : '')"></span>
                                            </span>
                                        </label>
                                    </template>
                                    <p x-show="addable.length === 0" x-cloak class="px-5 py-8 text-center text-sm text-slate-500">Every payroll employee of this scheme already has {{ $payComponent->code }} for this period.</p>
                                    <p x-show="addable.length > 0 && modalList.length === 0" x-cloak class="px-5 py-8 text-center text-sm text-slate-500">No employee matches the filters.</p>
                                </div>
                                <p x-show="modal.error" x-cloak x-text="modal.error" class="border-t border-slate-100 px-5 py-2 text-sm text-red-600"></p>
                                <div class="flex items-center justify-between gap-2 rounded-b-xl border-t border-slate-200 bg-slate-50 px-5 py-3">
                                    <span class="text-sm text-slate-500" x-text="pickedCount + ' selected'"></span>
                                    <div class="flex gap-2">
                                        <x-button variant="secondary" @click="modal.open = false">Cancel</x-button>
                                        <x-button variant="primary" @click="addSelected()" x-bind:disabled="pickedCount === 0 || modal.busy">Add selected</x-button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <script>
                    document.addEventListener('alpine:init', () => {
                        Alpine.data('payInputEntry', (cfg) => ({
                            cfg: cfg,
                            rows: cfg.rows.map((r) => Object.assign({}, r, { selected: false, s: (r.no + ' ' + r.name + ' ' + r.dept).toLowerCase() })),
                            addable: cfg.addable.map((a) => Object.assign({}, a, { s: (a.no + ' ' + a.name + ' ' + a.dept).toLowerCase() })),
                            q: '', dept: '', onlyNew: false, bulk: '',
                            modal: { open: false, q: '', dept: '', onlyNew: false, picked: {}, busy: false, error: '' },

                            /* amounts */
                            clean(v) { return String(v == null ? '' : v).replace(/[, ]/g, ''); },
                            valid(v) { return /^\d{1,13}(\.\d{1,2})?$/.test(this.clean(v)); },
                            fmt2(v) { const p = this.clean(v).split('.'); return p[0].replace(/^0+(?=\d)/, '') + '.' + (p[1] || '').padEnd(2, '0'); },
                            bad(r) { return this.clean(r.amount) !== '' && !this.valid(r.amount); },
                            dirty(r) { return this.clean(r.amount) !== '' && this.valid(r.amount) && this.fmt2(r.amount) !== r.orig; },
                            get bulkValid() { return this.valid(this.bulk); },
                            get dirtyRows() { return this.rows.filter((r) => this.dirty(r)); },
                            get dirtyCount() { return this.dirtyRows.length; },
                            get invalidCount() { return this.rows.filter((r) => this.bad(r)).length; },

                            /* filters and selection */
                            get departments() { return Array.from(new Set(this.rows.map((r) => r.dept).filter(Boolean))).sort(); },
                            get newCount() { return this.rows.filter((r) => r.isNew).length; },
                            get filtered() {
                                const q = this.q.trim().toLowerCase();
                                return this.rows.filter((r) => (!q || r.s.includes(q)) && (!this.dept || r.dept === this.dept) && (!this.onlyNew || r.isNew));
                            },
                            get selectedShown() { return this.filtered.filter((r) => r.selected).length; },
                            get allShownSelected() { const f = this.filtered; return f.length > 0 && f.every((r) => r.selected); },
                            toggleShown(on) { this.filtered.forEach((r) => { r.selected = on; }); },
                            fillSelected() { if (!this.bulkValid) return; const v = this.fmt2(this.bulk); this.filtered.filter((r) => r.selected).forEach((r) => { r.amount = v; }); },
                            fillShown() { if (!this.bulkValid) return; const v = this.fmt2(this.bulk); this.filtered.forEach((r) => { r.amount = v; }); },
                            resetSelected() { this.filtered.filter((r) => r.selected).forEach((r) => { r.amount = r.orig; }); },

                            /* "Add employees" popup */
                            get addableDepartments() { return Array.from(new Set(this.addable.map((a) => a.dept).filter(Boolean))).sort(); },
                            get modalList() {
                                const q = this.modal.q.trim().toLowerCase();
                                return this.addable.filter((a) => (!q || a.s.includes(q)) && (!this.modal.dept || a.dept === this.modal.dept) && (!this.modal.onlyNew || a.isNew));
                            },
                            get pickedCount() { return this.addable.filter((a) => this.modal.picked[a.id]).length; },
                            get allModalSelected() { const l = this.modalList; return l.length > 0 && l.every((a) => this.modal.picked[a.id]); },
                            toggleModalShown(on) { this.modalList.forEach((a) => { this.modal.picked[a.id] = on; }); },
                            openAdd() { this.modal.picked = {}; this.modal.error = ''; this.modal.q = ''; this.modal.dept = ''; this.modal.onlyNew = false; this.modal.open = true; },
                            headers() { return { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }; },
                            problem(j) { return Object.values(j.errors || {}).flat()[0] || j.message || 'Something went wrong.'; },
                            async addSelected() {
                                const ids = this.addable.filter((a) => this.modal.picked[a.id]).map((a) => a.id);
                                if (!ids.length) return;
                                this.modal.busy = true;
                                this.modal.error = '';
                                try {
                                    const r = await fetch(this.cfg.addUrl, { method: 'POST', headers: this.headers(), body: JSON.stringify({ period: this.cfg.period, component: this.cfg.component, employee_ids: ids }) });
                                    const j = await r.json().catch(() => ({}));
                                    if (r.status === 422) {
                                        this.modal.error = this.problem(j);
                                    } else if (!r.ok) {
                                        throw new Error();
                                    } else {
                                        const added = (j.rows || []).map((x) => Object.assign({}, x, { selected: false, s: (x.no + ' ' + x.name + ' ' + x.dept).toLowerCase() }));
                                        this.rows = this.rows.concat(added).sort((a, b) => (a.no < b.no ? -1 : a.no > b.no ? 1 : 0));
                                        this.addable = this.addable.filter((a) => !ids.includes(a.id));
                                        this.modal.picked = {};
                                        this.modal.open = false;
                                        window.toast(added.length + ' employee(s) added for this pay period only.');
                                    }
                                } catch (e) {
                                    this.modal.error = 'Could not add the employees. Please try again.';
                                }
                                this.modal.busy = false;
                            },
                            async removeAddition(r) {
                                if (!window.confirm('Take ' + r.name + ' out of this pay item for this period?')) return;
                                try {
                                    const res = await fetch(this.cfg.removeUrl + '/' + r.addId, { method: 'DELETE', headers: this.headers() });
                                    const j = await res.json().catch(() => ({}));
                                    if (res.status === 422) { window.toast(this.problem(j), 'error'); return; }
                                    if (!res.ok) throw new Error();
                                    this.rows = this.rows.filter((x) => x.id !== r.id);
                                    this.addable = this.addable.concat([{ id: r.id, no: r.no, name: r.name, dept: r.dept, isNew: r.isNew, s: r.s }]).sort((a, b) => (a.no < b.no ? -1 : a.no > b.no ? 1 : 0));
                                } catch (e) {
                                    window.toast('Could not take the employee out. Please try again.', 'error');
                                }
                            },
                        }));
                    });
                </script>
            @endif
        @endunless
    @endif
</div>
@endsection
