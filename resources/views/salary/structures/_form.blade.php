@php
    $creating = ! $structure->exists;
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
@endphp
<form method="POST" action="{{ $creating ? route('salary-structures.store') : route('salary-structures.update', $structure) }}" class="space-y-6"
    x-data="{
        all: @js($components),
        lines: @js($initialLines),
        chosen: '',
        get available() { const used = this.lines.map(l => String(l.id)); return this.all.filter(c => !c.inactive && !used.includes(String(c.id))); },
        info(id) { return this.all.find(c => String(c.id) === String(id)) ?? { code: '?', name: 'Unknown component', type: '', fixed: false, inactive: true }; },
        add() {
            if (!this.chosen) return;
            const c = this.info(this.chosen);
            this.lines.push({ id: c.id, amount: c.fixed && c.default !== null ? c.default : '', basis: 'monthly' });
            this.chosen = '';
        },
        remove(i) { this.lines.splice(i, 1); },
    }">
    @csrf
    @unless ($creating) @method('PUT') @endunless

    <x-card title="Structure">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-input name="name" label="Name" :value="old('name', $structure->name)" required placeholder="e.g. Casual - Standard" />
            @if ($schemeLocked)
                <div class="space-y-1.5">
                    <p class="block text-sm font-medium text-slate-700">Scheme</p>
                    <p class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $structure->scheme->name }}</p>
                    <p class="text-sm text-slate-500">The scheme cannot change once employees have been assigned this structure.</p>
                </div>
            @else
                <x-combobox name="scheme_id" label="Scheme" placeholder="Select a scheme" :value="old('scheme_id', $structure->scheme_id)"
                    :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()"
                    hint="Only schemes allocated to you are listed. Only employees of this scheme can be assigned the structure." />
            @endif
            <div class="sm:col-span-2"><x-input name="description" label="Description" :value="old('description', $structure->description)" /></div>
        </div>
    </x-card>

    <x-card title="Pay components">
        <div class="space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-full sm:w-96">
                    <x-combobox x-model="chosen" placeholder="Choose a component to add"
                        options-js="available.map(c => ({ value: c.id, label: c.code + ' · ' + c.name + ' (' + c.type + ')' }))" />
                </div>
                <x-button variant="secondary" @click="add()">Add component</x-button>
            </div>

            <div class="overflow-x-auto rounded-md border border-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr><th class="px-4 py-2.5 font-medium">Component</th><th class="px-4 py-2.5 font-medium">Default amount and rate basis</th><th class="px-4 py-2.5"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(l, i) in lines" :key="l.id">
                            <tr>
                                <td class="px-4 py-2.5">
                                    <input type="hidden" :name="'lines[' + i + '][component_id]'" :value="l.id">
                                    <span class="font-mono text-xs font-medium text-slate-900" x-text="info(l.id).code"></span>
                                    <span class="block text-xs text-slate-500" x-text="info(l.id).name + (info(l.id).type ? ' · ' + info(l.id).type : '')"></span>
                                    <span x-show="info(l.id).inactive" x-cloak class="text-xs text-amber-700">Inactive: remove it before saving.</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <div x-show="info(l.id).fixed" class="flex flex-wrap items-center gap-2">
                                        <div class="space-y-1.5">
                                            <input type="number" step="0.01" min="0" :name="'lines[' + i + '][default_amount]'" x-model="l.amount"
                                                placeholder="Set per employee" class="{{ $field }} max-w-50 font-mono">
                                        </div>
                                        <div class="space-y-1.5">
                                            <select :name="'lines[' + i + '][rate_basis]'" x-model="l.basis" aria-label="Rate basis" class="{{ $field }} w-32">
                                                <option value="monthly">Monthly</option>
                                                <option value="daily">Daily</option>
                                                <option value="hourly">Hourly</option>
                                            </select>
                                        </div>
                                    </div>
                                    <span x-show="!info(l.id).fixed" x-cloak class="text-xs text-slate-500">Calculated or entered each period</span>
                                </td>
                                <td class="px-4 py-2.5 text-right"><button type="button" @click="remove(i)" class="text-xs font-medium text-red-600 hover:underline">Remove</button></td>
                            </tr>
                        </template>
                        <tr x-show="lines.length === 0" x-cloak><td colspan="3" class="px-4 py-6 text-center text-slate-500">No components yet. Choose one above.</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="text-sm text-slate-500">A default amount and rate basis apply to fixed-amount components only. The basis says whether the amount is per month, per day or per hour. Both are copied to the employee when the structure is assigned and can then be changed for that employee.</p>
            @foreach ($errors->get('lines') as $m)<p class="text-sm text-red-600">{{ $m }}</p>@endforeach
            @foreach ($errors->get('lines.*') as $messages)@foreach ($messages as $m)<p class="text-sm text-red-600">{{ $m }}</p>@endforeach @endforeach
        </div>
    </x-card>

    <div class="flex justify-end gap-2">
        <x-button :href="$creating ? route('salary-structures.index') : route('salary-structures.show', $structure)" variant="secondary">Cancel</x-button>
        <x-button type="submit">{{ $creating ? 'Create structure' : 'Save changes' }}</x-button>
    </div>
</form>
