@extends('layouts.app')
@section('title', 'Leave types')

@section('content')
@php $field = 'rounded-md border border-slate-300 bg-white py-1.5 px-2.5 text-sm focus:outline-2 focus:outline-brand-600'; @endphp
<x-page-header title="Leave types" description="Leave types appear here when a load first sees them. Mark each as paid or no-pay: only confirmed no-pay leave reduces salary." />

@if ($types->contains(fn ($t) => $t->needsReview()))
    <x-alert type="warning" class="mb-4">Some leave types have not been reviewed. Payroll approval will be blocked while a type in use is undecided.</x-alert>
@endif

<x-card :padding="false">
    <x-table>
        <x-slot:head>
            <tr><th class="px-5 py-3 font-medium">Code</th><th class="px-5 py-3 font-medium">Leave type</th><th class="px-5 py-3 font-medium">Entries loaded</th>
                <th class="px-5 py-3 font-medium">Treated as</th><th class="px-5 py-3 text-right font-medium">Change</th></tr>
        </x-slot:head>
        @forelse ($types as $t)
            <tr>
                <td class="px-5 py-3 font-mono text-xs">{{ $t->code }}</td>
                <td class="px-5 py-3 font-medium text-slate-900">{{ $t->name }}</td>
                <td class="px-5 py-3">{{ number_format($entries[$t->code] ?? 0) }}</td>
                <td class="px-5 py-3">
                    @if ($t->needsReview())<x-badge color="amber">Needs review</x-badge>
                    @elseif ($t->is_no_pay)<x-badge color="red">No-pay</x-badge>
                    @else<x-badge color="green">Paid</x-badge>@endif
                </td>
                <td class="px-5 py-3">
                    <form method="POST" action="{{ route('leave-types.update', $t) }}" class="flex justify-end gap-2">
                        @csrf @method('PATCH')
                        <select name="is_no_pay" class="{{ $field }}" aria-label="Treatment for {{ $t->name }}">
                            @if ($t->needsReview())<option value="" disabled selected>Choose…</option>@endif
                            <option value="0" @selected($t->is_no_pay === false)>Paid leave</option>
                            <option value="1" @selected($t->is_no_pay === true)>No-pay leave</option>
                        </select>
                        <x-button type="submit" variant="secondary" size="sm">Save</x-button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-5 py-10 text-center text-slate-500">No leave types yet. They are created when leave is first loaded.</td></tr>
        @endforelse
    </x-table>
</x-card>
@endsection
