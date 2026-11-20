@php
    $ccService = app(\App\Services\CostCenterService::class);
    $ccNodes = $ccService->nodes();
    $ccHistory = \App\Models\EmployeeCostCenter::where('employee_id', $employee->id)->orderByDesc('effective_from')->orderByDesc('id')->get();
    $ccNow = $ccService->inForce([$employee->id], today())->get($employee->id);
    $ccLabel = $ccNow ? \App\Payroll\CostCenterTree::label($ccNodes, (int) $ccNow->cost_center_id, config('costcenters.separator')) : null;
    $ccCan = auth()->user()->can('cost-centers.assign');
    $ccInput = 'mt-1.5 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
@endphp
<div x-show="tab === 'costcenter'" x-cloak class="space-y-6">
    @unless ($ccNow)
        <x-alert type="warning">This employee has no cost center today. A pay run cannot include them until one is assigned.</x-alert>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Cost center today">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Cost center</dt><dd class="mt-0.5 text-base font-semibold text-slate-900">{{ $ccLabel ?? '—' }}</dd>
                        @if ($ccNow)<p class="text-xs text-slate-500">Since {{ $ccNow->effective_from->format('d M Y') }}</p>@endif</dd></div>
                    <div><dt class="text-slate-500">MiHCM cost center (for reference)</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $employee->mihcm_costcenter ?: '—' }}</dd></div>
                </dl>
            </x-card>

            <x-card title="History" :padding="false">
                <x-table>
                    <x-slot:head>
                        <tr><th class="px-5 py-3 font-medium">Effective from</th><th class="px-5 py-3 font-medium">Cost center</th><th class="px-5 py-3 font-medium">By</th><th class="px-5 py-3 font-medium">Reason</th></tr>
                    </x-slot:head>
                    @forelse ($ccHistory as $h)
                        <tr>
                            <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ $h->effective_from->format('d M Y') }}@if ($ccNow && $h->id === $ccNow->id)<x-badge color="green" class="ml-1">In force</x-badge>@endif</td>
                            <td class="px-5 py-3">{{ $h->path_label }}</td>
                            <td class="px-5 py-3 text-slate-500">{{ $h->created_by_name ?? 'System' }}<span class="block text-xs">{{ $h->created_at->format('d M Y H:i') }}</span></td>
                            <td class="px-5 py-3 text-slate-500">{{ $h->change_note }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-8 text-center text-slate-500">No cost center has been assigned yet.</td></tr>
                    @endforelse
                </x-table>
            </x-card>
        </div>

        @if ($ccCan)
            <x-card title="Change the cost center">
                <form method="POST" action="{{ route('cost-centers.employee.store', $employee) }}" class="space-y-4">
                    @csrf
                    <x-combobox name="cost_center_id" label="Cost center" :options="$ccService->leafOptions($ccNodes)" placeholder="Choose a cost center" hint="Only the lowest level of each branch can be chosen." />
                    <x-input name="effective_from" type="date" label="Effective from" :value="today()->toDateString()" required />
                    <x-input name="change_note" label="Reason" required />
                    <x-button type="submit" class="w-full">Save cost center</x-button>
                    <p class="text-xs text-slate-500">Each change is a new entry. The MiHCM sync never changes it.</p>
                </form>
            </x-card>
        @endif
    </div>
</div>
