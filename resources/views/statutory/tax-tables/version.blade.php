@extends('layouts.app')
@section('title', 'New version: '.$table->name)

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm font-mono focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $lump = $table->kind === 'lump_sum';
@endphp
<x-page-header :title="'New version: '.$table->name" description="A version is never edited later. A change, or the next tax year, is a new version with its own effective date.">
    <x-slot:actions><x-button :href="route('statutory.tax-tables')" variant="secondary">Cancel</x-button></x-slot:actions>
</x-page-header>

<form method="POST" action="{{ route('statutory.tax-tables.versions.store', ['table' => $table->code]) }}" class="space-y-6"
    x-data="{
        rows: @js($rows),
        add() { this.rows.push({ up_to: '', rate: '', fixed: '0' }); },
        remove(i) { this.rows.splice(i, 1); },
        over(i) { if (i === 0) return '0'; const u = String(this.rows[i - 1].up_to).replace(/[, ]/g, ''); return u === '' ? '?' : u.replace(/\B(?=(\d{3})+(?!\d))/g, ','); },
    }">
    @csrf

    @foreach ($errors->get('slabs') as $m)
        @foreach ((array) $m as $line)<x-alert type="error">{{ $line }}</x-alert>@endforeach
    @endforeach

    <x-card title="Version">
        <div class="grid gap-5 sm:grid-cols-2">
            <x-input name="label" label="Label" :value="old('label', $label)" required hint="For example Y/A 2026/27." />
            <x-input name="effective_from" type="date" label="Effective from" :value="old('effective_from', $effective)" required
                :hint="$latest ? 'Must be after the latest version ('.$latest->effective_from->format('d M Y').'). The tax year starts on 1 April.' : 'The tax year starts on 1 April.'" />
            <x-input name="source_note" label="Source" :value="old('source_note')" placeholder="e.g. IRD APIT Tax Table No. 01, 2026/27" />
            <x-input name="change_note" label="Reason" :value="old('change_note', $latest ? 'New tax year' : 'Initial setup')" required />
        </div>
    </x-card>

    <x-card title="Bands" :padding="false">
        <p class="border-b border-slate-100 px-5 py-3 text-sm text-slate-500">
            @if ($lump)
                Enter the bands of the lump-sum table as the IRD publishes them: for each band the yearly limit, the rate, and the fixed amount deducted.
            @else
                Enter the bands of the monthly table as the IRD publishes them: for each band the monthly limit, the rate, and the amount deducted ("less").
            @endif
            A band starts where the previous one ends. Leave the limit of the last band empty. The first band is normally the relief, with rate 0 and nothing deducted.
        </p>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500">
                    <tr><th class="px-5 py-3 font-medium">Band</th><th class="px-5 py-3 font-medium">Over (Rs)</th><th class="px-5 py-3 font-medium">Up to and including (Rs)</th>
                        <th class="px-5 py-3 font-medium">Rate (%)</th><th class="px-5 py-3 font-medium">{{ $lump ? 'Fixed amount (Rs)' : 'Less (Rs)' }}</th><th class="px-5 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="(r, i) in rows" :key="i">
                        <tr>
                            <td class="px-5 py-2.5 text-slate-500" x-text="i + 1"></td>
                            <td class="px-5 py-2.5 font-mono text-slate-700" x-text="over(i)"></td>
                            <td class="px-5 py-2.5"><input type="text" inputmode="decimal" :name="'slabs[' + i + '][up_to]'" x-model="r.up_to" :placeholder="i === rows.length - 1 ? 'no limit' : 'required'" autocomplete="off" class="{{ $field }} max-w-44"></td>
                            <td class="px-5 py-2.5"><input type="text" inputmode="decimal" :name="'slabs[' + i + '][rate]'" x-model="r.rate" autocomplete="off" class="{{ $field }} max-w-28"></td>
                            <td class="px-5 py-2.5"><input type="text" inputmode="decimal" :name="'slabs[' + i + '][fixed]'" x-model="r.fixed" autocomplete="off" class="{{ $field }} max-w-44"></td>
                            <td class="px-5 py-2.5 text-right"><button type="button" x-show="rows.length > 1" @click="remove(i)" class="text-xs font-medium text-red-600 hover:underline">Remove</button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-100 px-5 py-3"><button type="button" x-show="rows.length < 12" @click="add()" class="text-sm font-medium text-brand-700 hover:underline">+ Add a band</button></div>
        @foreach ($errors->get('slabs.*') as $messages)@foreach ($messages as $m)<p class="px-5 pb-3 text-sm text-red-600">{{ $m }}</p>@endforeach @endforeach
    </x-card>

    <div class="flex justify-end gap-2">
        <x-button :href="route('statutory.tax-tables')" variant="secondary">Cancel</x-button>
        <x-button type="submit">Save version</x-button>
    </div>
</form>
@endsection
