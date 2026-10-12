{{-- Reference card for the formula editor. Must sit inside the form's x-data (uses `formula`, `method`). Clicking a name appends it. --}}
@props(['others' => collect()])
<x-card title="Formula reference">
    <div class="space-y-4 text-sm">
        <div>
            <p class="mb-1.5 text-xs font-medium text-slate-500">Operators</p>
            <p class="font-mono text-xs text-slate-700">+ − * / ( )   10%   = &lt;&gt; &lt; &lt;= &gt; &gt;=   AND OR NOT</p>
        </div>
        <div>
            <p class="mb-1.5 text-xs font-medium text-slate-500">Functions</p>
            <ul class="space-y-1 font-mono text-xs text-slate-700">
                <li>IF(test, then, else)</li><li>MIN(a, b, …)  MAX(a, b, …)</li><li>ROUND(x, decimals)</li>
                <li>ROUNDUP(x, d)  ROUNDDOWN(x, d)</li><li>FLOOR(x)  CEIL(x)  ABS(x)  MOD(a, b)</li>
            </ul>
        </div>
        <div>
            <p class="mb-1.5 text-xs font-medium text-slate-500">System variables (click to add)</p>
            <div class="flex flex-wrap gap-1">
                @foreach (\App\Payroll\SystemVariables::all() as $name => [$label, $unit])
                    <button type="button" title="{{ $label }} ({{ $unit }})" @click="formula += (formula && !formula.endsWith(' ') ? ' ' : '') + '{{ $name }}'"
                        class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-700 hover:bg-brand-50 hover:text-brand-800">{{ $name }}</button>
                @endforeach
            </div>
        </div>
        @if ($others->isNotEmpty())
            <div>
                <p class="mb-1.5 text-xs font-medium text-slate-500">Other components (click to add)</p>
                <div class="flex flex-wrap gap-1">
                    @foreach ($others as $o)
                        <button type="button" title="{{ $o->name }}" @click="formula += (formula && !formula.endsWith(' ') ? ' ' : '') + '{{ $o->code }}'"
                            class="rounded bg-brand-50 px-1.5 py-0.5 font-mono text-xs text-brand-800 hover:bg-brand-100">{{ $o->code }}</button>
                    @endforeach
                </div>
            </div>
        @endif
        <p class="text-xs text-slate-500">Example: <span class="font-mono">IF(LWP_DAYS &gt; 0, BASIC / DAYS_IN_MONTH * LWP_DAYS, 0)</span></p>
    </div>
</x-card>
