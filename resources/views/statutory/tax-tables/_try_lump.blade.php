<x-card title="Try a lump sum">
    <div class="space-y-3" x-data="{
        month: @js(today()->format('Y-m')), f: { a: '', b: '', c: '', tax_on_a: '', tax_on_b: '', prior: '' }, res: null, busy: false,
        async run() {
            this.busy = true; this.res = null;
            try {
                const r = await fetch(@js(route('statutory.try')), { method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ table: 'apit_lump_sum', month: this.month, ...this.f }) });
                const j = await r.json();
                this.res = r.status === 422 ? { ok: false, message: Object.values(j.errors ?? {}).flat()[0] ?? 'Check the values.' } : j;
            } catch (e) { this.res = { ok: false, message: 'Could not calculate.' }; }
            this.busy = false;
        } }">
        <p class="text-sm text-slate-500">Nothing is saved. A = regular pay paid so far this tax year (this month included), B = regular pay still to come, C = lump sums paid and being paid now.</p>
        <x-input name="try_month_l" type="month" label="Month of payment" x-model="month" />
        <x-input name="try_a" label="Regular pay already paid (A)" x-model="f.a" inputmode="decimal" placeholder="0" />
        <x-input name="try_b" label="Regular pay still to come (B)" x-model="f.b" inputmode="decimal" placeholder="0" />
        <x-input name="try_c" label="Lump sums paid and being paid (C)" x-model="f.c" inputmode="decimal" placeholder="0" />
        <x-input name="try_tax_a" label="Monthly tax deducted on A" x-model="f.tax_on_a" inputmode="decimal" placeholder="0" />
        <x-input name="try_tax_b" label="Monthly tax still to be deducted on B" x-model="f.tax_on_b" inputmode="decimal" placeholder="0" />
        <x-input name="try_prior" label="Lump-sum tax deducted earlier" x-model="f.prior" inputmode="decimal" placeholder="0" />
        <x-button class="w-full" @click="run()" x-bind:disabled="busy">Calculate</x-button>

        <div x-show="res" x-cloak>
            <p x-show="res && !res.ok" class="rounded-md bg-red-50 p-3 text-sm text-red-700" x-text="res && res.message"></p>
            <div x-show="res && res.ok" class="rounded-md bg-brand-50 p-4">
                <p class="text-xs text-slate-500">Tax on this lump sum</p>
                <p class="mt-1 text-2xl font-semibold text-brand-800" x-text="res && res.ok ? 'Rs ' + res.tax : ''"></p>
                <ul class="mt-3 space-y-1 text-xs text-slate-600"><template x-for="l in ((res && res.lines) || [])"><li x-text="l"></li></template></ul>
            </div>
        </div>
    </div>
</x-card>
