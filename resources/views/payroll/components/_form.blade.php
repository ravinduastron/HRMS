{{-- Shared by create (header + first version) and "new version". Vars: $v, $others, optional $payComponent. --}}
@php
    $creating = ! isset($payComponent);
    $flag = fn (string $k) => old('_flags') ? (bool) old($k) : (bool) ($v[$k] ?? false);
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $label = 'block text-sm font-medium text-slate-700';
@endphp

<form method="POST" action="{{ $creating ? route('pay-components.store') : route('pay-components.versions.store', $payComponent) }}" class="grid gap-6 lg:grid-cols-3"
    x-data="{
        method: @js(old('calc_method', $v['calc_method'])),
        formula: @js(old('formula', $v['formula'] ?? '')),
        rate: @js(old('percentage_rate', $v['percentage_rate'])),
        of: @js(old('percentage_of', $v['percentage_of'] ?? [])),
        code: @js($creating ? old('code', '') : $payComponent->code),
        check: { state: 'idle', message: '' },
        async runCheck() {
            this.check = { state: 'busy', message: '' };
            try {
                const r = await fetch(@js(route('pay-components.check-formula')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ calc_method: this.method, formula: this.formula, percentage_rate: this.rate, percentage_of: this.of, code: this.code }),
                });
                if (!r.ok) throw new Error();
                const j = await r.json();
                this.check = { state: j.ok ? 'ok' : 'bad', message: j.ok ? 'Valid. Uses: ' + (j.references.join(', ') || 'no variables') : j.message };
            } catch (e) {
                this.check = { state: 'bad', message: 'Could not check the formula.' };
            }
        },
    }">
    @csrf
    <input type="hidden" name="_flags" value="1">

    <div class="space-y-6 lg:col-span-2">
        @if ($creating)
            <x-card title="Identity">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-input name="code" label="Code" x-model="code" :value="old('code')" autocomplete="off" required placeholder="e.g. TRANSPORT"
                        hint="Letters, digits and underscore. Used in formulas and cannot be changed later." />
                    <x-input name="name" label="Name" :value="old('name')" required placeholder="Shown on the payslip" />
                    <div class="space-y-1.5">
                        <label for="type" class="{{ $label }}">Type</label>
                        <select id="type" name="type" class="{{ $field }}" required>
                            @foreach (\App\Models\PayComponent::TYPES as $k => $t)<option value="{{ $k }}" @selected(old('type', 'earning') === $k)>{{ $t }}</option>@endforeach
                        </select>
                        @error('type')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <x-input name="payslip_order" type="number" label="Payslip order" :value="old('payslip_order', 100)" hint="Lower numbers appear first." />
                    <div class="sm:col-span-2"><x-input name="description" label="Description" :value="old('description')" /></div>
                    <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                        <input type="checkbox" name="show_on_payslip" value="1" class="size-4 accent-brand-700" @checked(old('_flags') ? old('show_on_payslip') : true)> Show on the payslip
                    </label>
                </div>
            </x-card>
        @endif

        <x-card title="How it is calculated">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-input name="effective_from" type="date" label="Effective from" :value="old('effective_from', $v['effective_from'])" required
                    :hint="$creating ? null : 'Must be after the current latest version. Earlier pay runs keep their original calculation.'" />
                <div class="space-y-1.5">
                    <label for="calc_method" class="{{ $label }}">Method</label>
                    <select id="calc_method" name="calc_method" x-model="method" class="{{ $field }}">
                        @foreach (\App\Models\PayComponentVersion::METHODS as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach
                    </select>
                    @error('calc_method')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div x-show="method === 'fixed'" class="sm:col-span-2">
                    <x-input name="default_amount" type="number" step="0.01" label="Default amount" :value="old('default_amount', $v['default_amount'])"
                        hint="Used when an employee has no amount of their own. Employee amounts are set in salary structures." />
                </div>

                <div x-show="method === 'percentage'" x-cloak class="space-y-4 sm:col-span-2">
                    <x-input name="percentage_rate" type="number" step="0.0001" label="Percentage" x-model="rate" :value="old('percentage_rate', $v['percentage_rate'])" hint="For example 10 means 10%." />
                    <div>
                        <p class="{{ $label }}">Percentage of</p>
                        <div class="mt-1.5 grid gap-2 sm:grid-cols-2">
                            @forelse ($others as $o)
                                <label class="flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-sm">
                                    <input type="checkbox" name="percentage_of[]" value="{{ $o->code }}" x-model="of" class="size-4 accent-brand-700">
                                    <span class="font-mono text-xs">{{ $o->code }}</span> <span class="text-slate-500">{{ $o->name }}</span>
                                </label>
                            @empty
                                <p class="text-sm text-slate-500">No other components yet.</p>
                            @endforelse
                        </div>
                        @error('percentage_of')<p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div x-show="method === 'formula'" x-cloak class="space-y-1.5 sm:col-span-2">
                    <label for="formula" class="{{ $label }}">Formula</label>
                    <textarea id="formula" name="formula" rows="4" x-model="formula" spellcheck="false" class="{{ $field }} font-mono"
                        placeholder="e.g. IF(LWP_DAYS > 0, BASIC / DAYS_IN_MONTH * LWP_DAYS, 0)"></textarea>
                    @error('formula')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                </div>

                <div x-show="method === 'formula' || method === 'percentage'" x-cloak class="sm:col-span-2">
                    <x-button variant="secondary" size="sm" @click="runCheck()">Check formula</x-button>
                    <p x-show="check.state !== 'idle'" x-cloak x-text="check.state === 'busy' ? 'Checking…' : check.message" role="status"
                        :class="check.state === 'ok' ? 'text-emerald-700' : (check.state === 'bad' ? 'text-red-600' : 'text-slate-500')" class="mt-2 text-sm"></p>
                </div>

                <p x-show="method === 'input'" x-cloak class="text-sm text-slate-500 sm:col-span-2">The amount is entered for each pay period (screen or Excel upload), for example bonuses and incentives.</p>
                <p x-show="method === 'system'" x-cloak class="text-sm text-slate-500 sm:col-span-2">The amount is supplied by another module (for example loan installments).</p>

                <div class="space-y-1.5">
                    <label for="rounding_mode" class="{{ $label }}">Rounding</label>
                    <select id="rounding_mode" name="rounding_mode" class="{{ $field }}">
                        @foreach (['half_up' => 'Nearest (0.5 rounds up)', 'up' => 'Always up', 'down' => 'Always down'] as $k => $t)<option value="{{ $k }}" @selected(old('rounding_mode', $v['rounding_mode']) === $k)>{{ $t }}</option>@endforeach
                    </select>
                </div>
                <x-input name="rounding_dp" type="number" min="0" max="6" label="Decimal places" :value="old('rounding_dp', $v['rounding_dp'])" required />
            </div>
        </x-card>

        <x-card title="Statutory and payment rules">
            <p class="mb-3 text-sm text-slate-500">Flags tell the payroll engine which base this component belongs to. Rates and tax tables are configured separately.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (['epf_applicable' => 'Counts for EPF', 'etf_applicable' => 'Counts for ETF', 'tax_applicable' => 'Taxable income', 'ot_base' => 'Part of the overtime base'] as $k => $t)
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="{{ $k }}" value="1" class="size-4 accent-brand-700" @checked($flag($k))> {{ $t }}</label>
                @endforeach
            </div>
            <div class="mt-5 grid gap-5 sm:grid-cols-3">
                <div class="space-y-1.5">
                    <label for="proration" class="{{ $label }}">Proration</label>
                    <select id="proration" name="proration" class="{{ $field }}">
                        @foreach (['none' => 'None', 'calendar_days' => 'By calendar days', 'working_days' => 'By working days'] as $k => $t)<option value="{{ $k }}" @selected(old('proration', $v['proration']) === $k)>{{ $t }}</option>@endforeach
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label for="payment_timing" class="{{ $label }}">Paid</label>
                    <select id="payment_timing" name="payment_timing" class="{{ $field }}">
                        @foreach (['regular' => 'With salary', 'separate' => 'Separately'] as $k => $t)<option value="{{ $k }}" @selected(old('payment_timing', $v['payment_timing']) === $k)>{{ $t }}</option>@endforeach
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label for="pay_cycle_rule" class="{{ $label }}">Applies in</label>
                    <select id="pay_cycle_rule" name="pay_cycle_rule" class="{{ $field }}">
                        @foreach (['every_period' => 'Every pay period', 'first_period' => 'First period of the month', 'last_period' => 'Last period of the month'] as $k => $t)<option value="{{ $k }}" @selected(old('pay_cycle_rule', $v['pay_cycle_rule']) === $k)>{{ $t }}</option>@endforeach
                    </select>
                </div>
            </div>
        </x-card>

        @unless ($creating)
            <x-card title="Reason for change">
                <x-input name="change_note" label="Why is this changing?" :value="old('change_note')" required />
            </x-card>
        @endunless

        <div class="flex justify-end gap-2">
            <x-button :href="$creating ? route('pay-components.index') : route('pay-components.show', $payComponent)" variant="secondary">Cancel</x-button>
            <x-button type="submit">{{ $creating ? 'Create component' : 'Save new version' }}</x-button>
        </div>
    </div>

    <div class="space-y-6"><x-formula-help :others="$others" /></div>
</form>
