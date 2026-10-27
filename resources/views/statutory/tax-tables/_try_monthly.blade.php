<x-card title="Try it">
    <div class="space-y-4" x-data="{
        month: @js(today()->format('Y-m')), income: '', res: null, busy: false,
        async run() {
            this.busy = true; this.res = null;
            try {
                const r = await fetch(@js(route('statutory.try')), { method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ table: 'apit_monthly', month: this.month, income: this.income }) });
                const j = await r.json();
                this.res = r.status === 422 ? { ok: false, message: Object.values(j.errors ?? {}).flat()[0] ?? 'Check the values.' } : j;
            } catch (e) { this.res = { ok: false, message: 'Could not calculate.' }; }
            this.busy = false;
        } }">
        <p class="text-sm text-slate-500">Nothing is saved. Uses the version in force in the month you choose.</p>
        <x-input name="try_month_m" type="month" label="Month" x-model="month" />
        <x-input name="try_income" label="Monthly taxable income (Rs)" x-model="income" inputmode="decimal" placeholder="e.g. 170000" />
        <x-button class="w-full" @click="run()" x-bind:disabled="busy">Calculate</x-button>

        <div x-show="res" x-cloak>
            <p x-show="res && !res.ok" class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="res && res.message"></p>
            <div x-show="res && res.ok" class="rounded-md bg-brand-50 p-4">
                <p class="text-xs text-slate-500">Monthly tax</p>
                <p class="mt-1 text-2xl font-semibold text-brand-800" x-text="res && res.ok ? 'Rs ' + res.tax : ''"></p>
                <ul class="mt-3 space-y-1 text-xs text-slate-600"><template x-for="l in ((res && res.lines) || [])"><li x-text="l"></li></template></ul>
            </div>
        </div>
    </div>
</x-card>
