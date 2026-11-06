{{-- Scheme and pay period selection. Vars: $schemes, $scheme, $periods, $period, $action (url). Choosing another scheme reloads the period list. --}}
@php $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600'; @endphp
<form method="GET" action="{{ $action }}" class="flex flex-wrap items-end gap-3">
    <div class="w-64"><x-combobox name="scheme" label="Scheme" :options="$schemes->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->values()" :value="$scheme?->id"
        @change="$nextTick(() => $root.closest('form').requestSubmit())" /></div>
    <div class="min-w-72 space-y-1.5">
        <label for="period" class="block text-sm font-medium text-slate-700">Pay period</label>
        <select id="period" name="period" class="{{ $field }}">
            @foreach ($periods as $p)
                <option value="{{ $p->id }}" @selected($period && $period->id === $p->id)>{{ $p->label() }} · {{ $p->period_start->format('d M') }} – {{ $p->period_end->format('d M Y') }} · {{ \App\Models\PayPeriod::STATUSES[$p->status] ?? $p->status }}</option>
            @endforeach
        </select>
    </div>
    <x-button type="submit" variant="secondary">Show</x-button>
</form>
@if ($scheme && $periods->isEmpty())
    <x-alert type="warning" class="mt-4">{{ $scheme->name }} has no pay periods yet. @can('pay-periods.generate')<a href="{{ route('pay-periods.index', ['scheme' => $scheme->id]) }}" class="font-medium underline">Generate them</a>.@endcan</x-alert>
@endif
