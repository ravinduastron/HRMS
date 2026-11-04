@php
    // HirePurchase's own scope applies, so only hire purchases of employees the user may see are returned.
    $hps = \App\Models\HirePurchase::with('vendor')->where('employee_id', $employee->id)->orderByDesc('id')->get();
    $hpPending = \App\Models\HirePurchaseInstallment::whereIn('hire_purchase_id', $hps->pluck('id'))->where('status', 'pending')->orderBy('due_month')->orderBy('seq')->get()->groupBy('hire_purchase_id');
    $hpService = app(\App\Services\HirePurchaseService::class);
    $m = fn ($v) => \App\Payroll\Statutory\Format::money((string) $v);
    $color = ['active' => 'green', 'completed' => 'slate', 'stopped' => 'red'];
    $activeHps = $hps->where('status', 'active');
    $hpTotal = $activeHps->reduce(fn ($c, $p) => \App\Payroll\Decimal::add($c, $hpService->remaining($hpPending[$p->id] ?? collect())), '0');
    $hpLeft = $activeHps->isNotEmpty() && \App\Services\HirePurchaseService::hasLeft($employee);
@endphp
<div x-show="tab === 'hirepurchases'" x-cloak class="space-y-6">
    @if ($hpLeft)
        <x-alert type="warning" title="This employee has left or is inactive">Hire purchase installments are still due. Nothing is stopped automatically. @can('hire-purchases.stop')Open a hire purchase and use "Stop deductions" if it should not be recovered from pay.@endcan</x-alert>
    @endif
    <x-card title="Hire purchases" :padding="false">
        <x-slot:actions>
            <div class="flex items-center gap-3">
                @if ($activeHps->isNotEmpty())
                    <span class="text-sm text-slate-500">Left to deduct <span class="font-mono font-medium text-slate-900">{{ $m($hpTotal) }}</span> on {{ $activeHps->count() }} active {{ \Illuminate\Support\Str::plural('hire purchase', $activeHps->count()) }}</span>
                @endif
                @can('hire-purchases.manage')<x-button :href="route('hire-purchases.create', ['employee_id' => $employee->id])" size="sm"><x-icon name="plus" class="size-4" /> New hire purchase</x-button>@endcan
            </div>
        </x-slot:actions>
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Hire purchase</th><th class="px-5 py-3 font-medium">Vendor</th>
                    <th class="px-5 py-3 text-right font-medium">Next installment</th><th class="px-5 py-3 text-right font-medium">Left to deduct</th>
                    <th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th>
                </tr>
            </x-slot:head>
            @forelse ($hps as $p)
                @php $s = $hpService->summary($hpPending[$p->id] ?? collect()); @endphp
                <tr>
                    <td class="px-5 py-3"><a href="{{ route('hire-purchases.show', $p) }}" class="font-medium text-slate-900 hover:underline">{{ $p->reference }}</a><p class="text-xs text-slate-500">{{ $p->item_description }}</p></td>
                    <td class="px-5 py-3">{{ $p->vendor->name }}</td>
                    <td class="px-5 py-3 text-right">@if ($s['next'])<span class="font-mono">{{ $m($s['next']->amount) }}</span><p class="text-xs text-slate-500">{{ $s['next']->due_month->format('M Y') }}</p>@else<span class="text-slate-400">—</span>@endif</td>
                    <td class="px-5 py-3 text-right">@if ($p->status === 'active')<span class="font-mono">{{ $m($s['total']) }}</span><p class="text-xs text-slate-500">{{ $s['count'] }} {{ \Illuminate\Support\Str::plural('installment', $s['count']) }}</p>@else<span class="text-slate-400">—</span>@endif</td>
                    <td class="px-5 py-3"><x-badge :color="$color[$p->status] ?? 'slate'">{{ ucfirst($p->status) }}</x-badge></td>
                    <td class="px-5 py-3 text-right"><x-button :href="route('hire-purchases.show', $p)" variant="ghost" size="sm">Open</x-button></td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-8 text-center text-slate-500">This employee has no hire purchases.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
