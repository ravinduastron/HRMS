@php
    // Loan's own scope applies, so only loans of employees the user may see are returned.
    $loans = \App\Models\Loan::with('type')->where('employee_id', $employee->id)->orderByDesc('id')->get();
    $outstanding = app(\App\Services\LoanService::class)->outstandingFor($loans);
    $next = \App\Models\LoanInstallment::whereIn('loan_id', $loans->pluck('id'))->where('status', 'pending')->orderBy('due_month')->orderBy('seq')->get()->groupBy('loan_id')->map->first();
    $m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
    $color = ['active' => 'green', 'settled' => 'slate', 'cancelled' => 'red'];
    $activeLoans = $loans->where('status', 'active');
    $totalOutstanding = $activeLoans->reduce(fn ($c, $l) => \App\Payroll\Decimal::add($c, (string) ($outstanding[$l->id] ?? '0')), '0');
@endphp
<div x-show="tab === 'loans'" x-cloak class="space-y-6">
    <x-card title="Loans" :padding="false">
        <x-slot:actions>
            <div class="flex items-center gap-3">
                @if ($activeLoans->isNotEmpty())
                    <span class="text-sm text-slate-500">Outstanding <span class="font-mono font-medium text-slate-900">{{ $m($totalOutstanding) }}</span> on {{ $activeLoans->count() }} active {{ \Illuminate\Support\Str::plural('loan', $activeLoans->count()) }}</span>
                @endif
                @can('loans.manage')<x-button :href="route('loans.create', ['employee_id' => $employee->id])" size="sm"><x-icon name="plus" class="size-4" /> New loan</x-button>@endcan
            </div>
        </x-slot:actions>
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Loan</th><th class="px-5 py-3 font-medium">Interest</th>
                    <th class="px-5 py-3 text-right font-medium">Outstanding</th><th class="px-5 py-3 text-right font-medium">Next installment</th>
                    <th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th>
                </tr>
            </x-slot:head>
            @forelse ($loans as $l)
                @php $n = $next[$l->id] ?? null; @endphp
                <tr>
                    <td class="px-5 py-3"><a href="{{ route('loans.show', $l) }}" class="font-medium text-slate-900 hover:underline">{{ $l->reference }}</a><p class="text-xs text-slate-500">{{ $l->type->name }} · paid out {{ $l->disbursed_on->format('d M Y') }}</p></td>
                    <td class="px-5 py-3">{{ \App\Payroll\Decimal::plain((string) $l->current_rate) }}%<p class="text-xs text-slate-500">{{ \App\Payroll\Loans\LoanSchedule::METHODS[$l->interest_method] ?? $l->interest_method }}</p></td>
                    <td class="px-5 py-3 text-right font-mono">{{ $l->status === 'active' ? $m($outstanding[$l->id] ?? '0') : '—' }}</td>
                    <td class="px-5 py-3 text-right">@if ($n)<span class="font-mono">{{ $m($n->total()) }}</span><p class="text-xs text-slate-500">{{ $n->due_month->format('M Y') }}</p>@else<span class="text-slate-400">—</span>@endif</td>
                    <td class="px-5 py-3"><x-badge :color="$color[$l->status] ?? 'slate'">{{ ucfirst($l->status) }}</x-badge>@if ($l->source === 'import')<x-badge color="brand" class="ml-1">Imported</x-badge>@endif</td>
                    <td class="px-5 py-3 text-right"><x-button :href="route('loans.show', $l)" variant="ghost" size="sm">Open</x-button></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-8 text-center text-slate-500">This employee has no loans.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
