@php
    $current = $salary['current'] ?? null;
    $next = $salary['next'] ?? null;
    $versions = $salary['versions'] ?? collect();
    $viewDate = $salaryDate ?? today();
    $isToday = $viewDate->isToday();
    $canManage = auth()->user()->can('employee-salary.manage');
    $canReveal = auth()->user()->can('employee-salary.reveal');
    $input = 'mt-1.5 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $typeColor = ['earning' => 'green', 'deduction' => 'red', 'employer_contribution' => 'brand', 'info' => 'slate'];
    $lines = $current ? $current->lines->sortBy(fn ($l) => $l->component?->payslip_order ?? 999)->values() : collect();
    $isFixed = fn ($l) => $l->component?->latestVersion()?->calc_method === 'fixed';
    $fixedLines = $lines->filter(fn ($l) => $isFixed($l) && $l->component)->values();
    $hasAmounts = $lines->contains(fn ($l) => $isFixed($l) && $l->amount !== null);
    $methodText = ['formula' => 'Calculated', 'percentage' => 'Calculated', 'input' => 'Entered each period', 'system' => 'From another module'];
    $viewUrl = fn ($date) => route('employees.show', ['employee' => $employee, 'tab' => 'salary', 'salary_date' => $date]);
    $bases = ['monthly' => 'Monthly', 'daily' => 'Daily', 'hourly' => 'Hourly'];
    $basisText = ['monthly' => 'per month', 'daily' => 'per day', 'hourly' => 'per hour'];
    $maxHours = $current?->max_hours !== null ? rtrim(rtrim((string) $current->max_hours, '0'), '.') : null;
    $exempt = (bool) ($current?->statutory_exempt);
@endphp
<div x-show="tab === 'salary'" x-cloak class="space-y-6">
    @if (! $employee->scheme_id)
        <x-alert type="warning" title="No scheme">This employee has no scheme yet, so a salary structure cannot be assigned. Structures belong to a scheme.</x-alert>
    @endif

    {{-- View the salary as it is (or will be) on a chosen date --}}
    <form method="GET" action="{{ route('employees.show', $employee) }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
        <input type="hidden" name="tab" value="salary">
        <label class="block text-sm font-medium text-slate-700">View salary as at
            <input type="date" name="salary_date" value="{{ $viewDate->toDateString() }}" required class="{{ $input }} w-48">
        </label>
        <x-button type="submit" variant="secondary">View</x-button>
        @unless ($isToday)<a href="{{ $viewUrl(null) }}" class="pb-2 text-sm font-medium text-brand-700 hover:underline">Back to today</a>@endunless
        <p class="pb-2 text-sm text-slate-500">Shows the components in force on that date. Click a row in the history below to jump to its date.</p>
    </form>

    @unless ($isToday)
        <x-alert type="info">You are viewing the salary as at {{ $viewDate->format('d M Y') }}, not today's salary. New changes you make default to this date.</x-alert>
    @endunless
    @if ($next)
        <x-alert type="info">The next salary change takes effect on {{ $next->effective_from->format('d M Y') }}.</x-alert>
    @endif

    <div x-data="{ amounts: null, loading: false, timer: null,
            async reveal() {
                this.loading = true;
                try {
                    const r = await fetch(@js(route('employees.salary.reveal', [$employee, 'date' => $viewDate->toDateString()])), { headers: { Accept: 'application/json' } });
                    if (!r.ok) throw new Error();
                    const j = await r.json();
                    this.amounts = j.amounts;
                    this.timer = setTimeout(() => this.amounts = null, (j.seconds || 20) * 1000);
                } catch (e) { window.toast('Could not load the salary amounts.', 'error'); }
                this.loading = false;
            },
            hide() { this.amounts = null; clearTimeout(this.timer); },
            fmt(v) { const p = String(v).split('.'); return p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (p[1] || '').padEnd(2, '0'); } }">
        <x-card :title="$isToday ? 'Current salary' : 'Salary as at '.$viewDate->format('d M Y')" :padding="false">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($canReveal && $hasAmounts)
                        <x-button variant="secondary" size="sm" x-show="amounts === null" @click="reveal()" x-bind:disabled="loading">Show amounts</x-button>
                        <x-button variant="secondary" size="sm" x-show="amounts !== null" x-cloak @click="hide()">Hide amounts</x-button>
                    @endif
                    @if ($canManage && $current && $fixedLines->isNotEmpty())
                        <x-confirm-action :action="route('employees.salary.amounts', $employee)" method="PATCH" title="Set amounts" confirm="Save amounts" variant="primary">
                            <x-slot:trigger><x-button variant="secondary" size="sm">Set amounts</x-button></x-slot:trigger>
                            <p class="mt-1 text-left text-xs text-slate-500">Enter the amounts you want to set or change. Each one keeps its own rate basis (monthly, daily or hourly); use "Change amount" on a line to change the basis. Leave a box empty to keep that amount as it is. All of them are saved as one change.</p>
                            <div class="mt-3 max-h-64 space-y-3 overflow-y-auto pr-1 text-left">
                                @foreach ($fixedLines as $l)
                                    <label class="block text-sm font-medium text-slate-700"><span class="font-mono text-xs">{{ $l->component_code }}</span> <span class="font-normal text-slate-500">{{ $l->component_name }} · {{ $basisText[$l->rate_basis ?? 'monthly'] }}</span>@if ($l->amount === null) <span class="text-xs text-amber-700">· amount needed</span>@endif
                                        <input type="text" name="amounts[{{ $l->pay_component_id }}]" inputmode="decimal" autocomplete="off" placeholder="Unchanged" class="{{ $input }} font-mono">
                                    </label>
                                @endforeach
                            </div>
                            @include('employees.tabs._salary_fields')
                        </x-confirm-action>
                    @endif
                    @if ($canManage && $current)
                        <x-confirm-action :action="route('employees.salary.rules', $employee)" method="PATCH" title="Pay rules" confirm="Save pay rules" variant="primary">
                            <x-slot:trigger><x-button variant="secondary" size="sm">Pay rules</x-button></x-slot:trigger>
                            <div class="mt-3 space-y-3 text-left">
                                <label class="block text-sm font-medium text-slate-700">Most hours paid per month
                                    <input type="text" name="max_hours" inputmode="decimal" autocomplete="off" value="{{ $maxHours }}" placeholder="No limit" class="{{ $input }} font-mono">
                                </label>
                                <p class="text-xs text-slate-500">All hours worked count towards the limit, overtime included. Hours above it are not paid.</p>
                                <input type="hidden" name="statutory_exempt" value="0">
                                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="statutory_exempt" value="1" class="size-4 accent-brand-700" @checked($exempt)> No EPF / MSPS / ETF for this employee</label>
                            </div>
                            @include('employees.tabs._salary_fields')
                        </x-confirm-action>
                    @endif
                    @if ($canManage && $current)
                        <x-confirm-action :action="route('employees.salary.components.store', $employee)" title="Add a pay component" confirm="Add component" variant="primary">
                            <x-slot:trigger><x-button variant="secondary" size="sm">Add component</x-button></x-slot:trigger>
                            <div class="mt-3 space-y-3 text-left">
                                <x-combobox name="pay_component_id" label="Component" placeholder="Choose a component" :options="$addable" />
                                <label class="block text-sm font-medium text-slate-700">Amount
                                    <input type="text" name="amount" inputmode="decimal" autocomplete="off" placeholder="Needed for fixed-amount components" class="{{ $input }} font-mono">
                                </label>
                                <label class="block text-sm font-medium text-slate-700">Rate basis
                                    <select name="rate_basis" class="{{ $input }}">
                                        @foreach ($bases as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach
                                    </select>
                                </label>
                                <p class="text-xs text-slate-500">The amount is per month, per day or per hour, as chosen. Calculated components need no amount or basis. Components used in a formula must be present too.</p>
                            </div>
                            @include('employees.tabs._salary_fields')
                        </x-confirm-action>
                    @endif
                    @if ($canManage)
                        @if ($structures->isNotEmpty())
                            <x-confirm-action :action="route('employees.salary.assign', $employee)" :title="$current ? 'Assign a different structure?' : 'Assign a salary structure'" confirm="Assign structure" variant="primary">
                                <x-slot:trigger><x-button size="sm">{{ $current ? 'Change structure' : 'Assign structure' }}</x-button></x-slot:trigger>
                                <div class="mt-3 text-left">
                                    <x-combobox name="structure_id" label="Structure" placeholder="Choose a structure" :options="$structures->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" />
                                </div>
                                @if ($current)
                                    <p class="mt-3 text-sm text-amber-700">The employee's components are replaced by the structure's from the effective date, including any added or changed for this employee, and rate bases go back to monthly. The hours limit and statutory exemption are kept. Earlier salary versions are kept.</p>
                                @endif
                                @include('employees.tabs._salary_fields')
                            </x-confirm-action>
                        @elseif ($employee->scheme_id)
                            <span class="text-xs text-slate-500">No active structure for this scheme.</span>
                        @endif
                    @endif
                </div>
            </x-slot:actions>

            @if ($current)
                <div class="grid gap-x-6 gap-y-3 border-b border-slate-100 px-5 py-4 text-sm sm:grid-cols-3">
                    <div><p class="text-xs text-slate-500">Structure</p><p class="mt-0.5 font-medium text-slate-900">{{ $current->structure_name ?? 'None' }}</p></div>
                    <div><p class="text-xs text-slate-500">In force since</p><p class="mt-0.5 font-medium text-slate-900">{{ $current->effective_from->format('d M Y') }}</p></div>
                    <div><p class="text-xs text-slate-500">Last change</p><p class="mt-0.5 font-medium text-slate-900">{{ $current->change_note }}<span class="block text-xs font-normal text-slate-500">{{ $current->created_by_name ?? 'System' }}</span></p></div>
                    <div><p class="text-xs text-slate-500">Hours limit</p><p class="mt-0.5 font-medium text-slate-900">{{ $maxHours !== null ? $maxHours.' hours a month' : 'No limit' }}</p></div>
                    <div><p class="text-xs text-slate-500">EPF / MSPS / ETF</p><p class="mt-0.5">@if ($exempt)<x-badge color="amber">Exempt</x-badge>@else<span class="font-medium text-slate-900">Applies</span>@endif</p></div>
                </div>

                <x-table>
                    <x-slot:head>
                        <tr>
                            <th class="px-5 py-3 font-medium">Component</th>
                            <th class="px-5 py-3 font-medium">Type</th>
                            <th class="px-5 py-3 font-medium">Source</th>
                            <th class="px-5 py-3 text-right font-medium">Amount</th>
                            @if ($canManage)<th class="px-5 py-3"></th>@endif
                        </tr>
                    </x-slot:head>
                    @foreach ($lines as $l)
                        @php $fixed = $isFixed($l); $c = $l->component; $basis = $l->rate_basis ?? 'monthly'; @endphp
                        <tr>
                            <td class="px-5 py-3"><span class="font-mono text-xs font-medium text-slate-900">{{ $l->component_code }}</span><span class="block text-xs text-slate-500">{{ $l->component_name }}</span></td>
                            <td class="px-5 py-3">@if ($c)<x-badge :color="$typeColor[$c->type] ?? 'slate'">{{ \App\Models\PayComponent::TYPES[$c->type] }}</x-badge>@endif</td>
                            <td class="px-5 py-3 text-xs text-slate-600">{{ $l->source === 'custom' ? 'Added for this employee' : 'From structure' }}</td>
                            <td class="px-5 py-3 text-right">
                                @if ($fixed)
                                    @if ($l->amount === null)
                                        <x-badge color="amber">Amount needed</x-badge>
                                    @else
                                        <span x-show="amounts === null" class="font-mono text-slate-500">••••••</span>
                                        <span x-show="amounts !== null" x-cloak class="font-mono font-medium text-slate-900" x-text="amounts && amounts[{{ $l->id }}] !== undefined ? fmt(amounts[{{ $l->id }}]) : ''"></span>
                                        <span class="block text-xs text-slate-500">{{ $basisText[$basis] ?? 'per month' }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-slate-500">{{ $methodText[$c?->latestVersion()?->calc_method] ?? 'Calculated' }}</span>
                                @endif
                            </td>
                            @if ($canManage)
                                <td class="px-5 py-3">
                                    <div class="flex justify-end gap-1">
                                        @if ($fixed && $c)
                                            <x-confirm-action :action="route('employees.salary.components.amount', [$employee, $c])" method="PATCH" :title="'Change '.$l->component_code.' amount'" confirm="Save amount" variant="primary">
                                                <x-slot:trigger><x-button variant="ghost" size="sm">Change amount</x-button></x-slot:trigger>
                                                <label class="mt-3 block text-left text-sm font-medium text-slate-700">New amount
                                                    <input type="text" name="amount" inputmode="decimal" required autocomplete="off" class="{{ $input }} font-mono">
                                                </label>
                                                <label class="mt-3 block text-left text-sm font-medium text-slate-700">Rate basis
                                                    <select name="rate_basis" class="{{ $input }}">
                                                        @foreach ($bases as $k => $t)<option value="{{ $k }}" @selected($basis === $k)>{{ $t }}</option>@endforeach
                                                    </select>
                                                </label>
                                                @include('employees.tabs._salary_fields')
                                            </x-confirm-action>
                                        @endif
                                        @if ($c)
                                            <x-confirm-action :action="route('employees.salary.components.destroy', [$employee, $c])" method="DELETE" :title="'Remove '.$l->component_code.'?'" confirm="Remove">
                                                <x-slot:trigger><x-button variant="ghost" size="sm"><span class="text-red-600">Remove</span></x-button></x-slot:trigger>
                                                It is removed from this employee only, from the effective date. The structure is not changed.
                                                @include('employees.tabs._salary_fields')
                                            </x-confirm-action>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </x-table>
            @else
                <p class="px-5 py-6 text-sm text-slate-500">No salary is in force on {{ $viewDate->format('d M Y') }}.@if ($canManage && $structures->isNotEmpty() && ! $versions->count()) Assign a salary structure to start.@endif</p>
            @endif
        </x-card>
    </div>

    <x-card title="Salary history" :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Effective from</th>
                    <th class="px-5 py-3 font-medium">Change</th>
                    <th class="px-5 py-3 font-medium">Structure</th>
                    <th class="px-5 py-3 font-medium">Components</th>
                    <th class="px-5 py-3 font-medium">By</th>
                    <th class="px-5 py-3 font-medium">Reason</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </x-slot:head>
            @forelse ($versions as $v)
                @php
                    $older = $versions[$loop->index + 1] ?? null;
                    $now = $v->lines->pluck('component_name', 'pay_component_id');
                    $before = $older ? $older->lines->pluck('component_name', 'pay_component_id') : collect();
                    $added = $older ? $now->diffKeys($before)->values() : collect();
                    $removed = $older ? $before->diffKeys($now)->values() : collect();
                    $vHours = $v->max_hours !== null ? rtrim(rtrim((string) $v->max_hours, '0'), '.') : null;
                    $ruleChange = $older && ((string) $v->max_hours !== (string) $older->max_hours || (bool) $v->statutory_exempt !== (bool) $older->statutory_exempt);
                @endphp
                <tr>
                    <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ $v->effective_from->format('d M Y') }}
                        @if ($current && $v->id === $current->id)<x-badge :color="$isToday ? 'green' : 'brand'" class="ml-1">{{ $isToday ? 'In force' : 'Viewed' }}</x-badge>@endif</td>
                    <td class="px-5 py-3">{{ $v->change_type === 'assigned' ? 'Structure assigned' : 'Changed' }}</td>
                    <td class="px-5 py-3">{{ $v->structure_name ?? '—' }}</td>
                    <td class="max-w-sm px-5 py-3 text-xs text-slate-600">
                        {{ $v->lines->pluck('component_name')->implode(', ') }}
                        @if ($added->isNotEmpty())<span class="mt-1 block text-emerald-700">Added: {{ $added->implode(', ') }}</span>@endif
                        @if ($removed->isNotEmpty())<span class="mt-0.5 block text-red-700">Removed: {{ $removed->implode(', ') }}</span>@endif
                        @if ($ruleChange)<span class="mt-0.5 block text-sky-700">Pay rules: {{ $vHours !== null ? $vHours.' hours a month' : 'no hours limit' }}, EPF / MSPS / ETF {{ $v->statutory_exempt ? 'exempt' : 'apply' }}</span>@endif
                    </td>
                    <td class="px-5 py-3 text-slate-500">{{ $v->created_by_name ?? 'System' }}<span class="block text-xs">{{ $v->created_at->format('d M Y H:i') }}</span></td>
                    <td class="px-5 py-3 text-slate-500">{{ $v->change_note }}</td>
                    <td class="px-5 py-3 text-right"><a href="{{ $viewUrl($v->effective_from->toDateString()) }}" class="text-xs font-medium text-brand-700 hover:underline">View</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-5 py-8 text-center text-slate-500">No salary history yet.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
