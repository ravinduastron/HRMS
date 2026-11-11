@php
    $statutory = app(\App\Services\StatutoryService::class);
    $eff = $statutory->effectiveEmployeeRate($employee);
    $rateSet = $statutory->ratesAt();
    $rates = $rateSet?->rates();
    $fundKey = $employee->provident_fund === 'msps' ? 'msps' : 'epf';
    $fundName = strtoupper($fundKey);
    $default = $rates[$fundKey.'_employee'] ?? null;
    $employerRate = $rates[$fundKey.'_employer'] ?? null;
    $etfRate = $rates['etf_employer'] ?? null;
    $rateHistory = \App\Models\EmployeeContributionRate::where('employee_id', $employee->id)->orderByDesc('effective_from')->orderByDesc('id')->get();
    $exempt = (bool) app(\App\Services\EmployeeSalaryService::class)->inForce($employee)?->statutory_exempt;
    $canSetRate = auth()->user()->can('statutory.employee-rates');
@endphp
<div x-show="tab === 'statutory'" x-cloak class="space-y-6">
    @unless ($rates)
        <x-alert type="warning">No contribution rates are in force yet, so the defaults below are blank. They are added under Payroll &gt; Statutory &gt; Contribution rates.</x-alert>
    @endunless
    @if ($exempt)
        <x-alert type="info">This employee is marked "No EPF / MSPS / ETF" on their salary, so no contribution is deducted whatever the rates below say.</x-alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Rate used today">
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">Employee contribution ({{ $fundName }})</dt>
                        <dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ $eff['rate'] !== null ? $eff['rate'].'%' : '—' }}</dd></div>
                    <div><dt class="text-slate-500">Source</dt>
                        <dd class="mt-0.5">@if ($eff['source'] === 'employee')<x-badge color="amber">Own rate</x-badge>@elseif ($eff['source'] === 'default')<x-badge>{{ $fundName }} default</x-badge>@else<x-badge color="red">No rates set up</x-badge>@endif</dd></div>
                    <div><dt class="text-slate-500">{{ $fundName }} default</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $default !== null ? $default.'%' : '—' }}</dd></div>
                    <div><dt class="text-slate-500">Employer contribution</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $employerRate !== null ? $employerRate.'%' : '—' }}</dd></div>
                    <div><dt class="text-slate-500">ETF (employer)</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $etfRate !== null ? $etfRate.'%' : '—' }}</dd></div>
                </dl>
                <p class="mt-4 text-sm text-slate-500">Only the employee's own contribution can differ. The employer contribution and ETF use the rates under Payroll &gt; Statutory &gt; Contribution rates and apply to everyone.</p>
            </x-card>

            <x-card title="Rate history" :padding="false">
                <x-table>
                    <x-slot:head>
                        <tr><th class="px-5 py-3 font-medium">Effective from</th><th class="px-5 py-3 text-right font-medium">Employee rate</th><th class="px-5 py-3 font-medium">By</th><th class="px-5 py-3 font-medium">Reason</th></tr>
                    </x-slot:head>
                    @forelse ($rateHistory as $h)
                        <tr>
                            <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ $h->effective_from->format('d M Y') }}</td>
                            <td class="px-5 py-3 text-right font-mono">{{ $h->employee_rate !== null ? \App\Payroll\Decimal::plain((string) $h->employee_rate).'%' : 'Default' }}</td>
                            <td class="px-5 py-3 text-slate-500">{{ $h->created_by_name ?? 'System' }}<span class="block text-xs">{{ $h->created_at->format('d M Y H:i') }}</span></td>
                            <td class="px-5 py-3 text-slate-500">{{ $h->change_note }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-8 text-center text-slate-500">No own rate has been set. The default of the fund applies.</td></tr>
                    @endforelse
                </x-table>
            </x-card>
        </div>

        @if ($canSetRate)
            <x-card title="Set the employee rate">
                <form method="POST" action="{{ route('statutory.employee-rates.store', $employee) }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="return" value="employee">
                    <x-input name="employee_rate" label="Employee contribution (%)" :value="old('employee_rate')" inputmode="decimal" placeholder="Empty = use the default"
                        hint="Leave empty to go back to the default of the fund. 0 means no employee contribution." />
                    <x-input name="effective_from" type="date" label="Effective from" :value="old('effective_from', today()->toDateString())" required />
                    <x-input name="change_note" label="Reason" :value="old('change_note')" required />
                    <x-button type="submit" class="w-full">Save rate</x-button>
                    <p class="text-xs text-slate-500">Each change is a new entry with its own date. Several can share a date; the last one saved is in force.</p>
                </form>
            </x-card>
        @endif
    </div>
</div>
