@php
    $entries = $leave['entries'] ?? collect();
    $types = $leave['types'] ?? collect();
    $session = ['FH' => 'first half', 'SH' => 'second half'];
    $tabKey = 'leave';
@endphp
<div x-show="tab === 'leave'" x-cloak class="space-y-6">
    @include('employees.tabs._att_range')

    <x-card title="Leave in this range" :padding="false">
        <p class="border-b border-slate-100 px-5 py-3 text-sm text-slate-500">Only confirmed leave of a no-pay type reduces salary. Pending leave is shown but not deducted.</p>
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">Leave type</th><th class="px-5 py-3 font-medium">From – to</th><th class="px-5 py-3 text-right font-medium">Days</th><th class="px-5 py-3 font-medium">Status</th></tr>
            </x-slot:head>
            @forelse ($entries as $l)
                @php $t = $types[$l->leave_type_code] ?? null; @endphp
                <tr @class(['opacity-60' => $l->missing_since])>
                    <td class="px-5 py-3">{{ $l->leave_type_name }}
                        <span class="ml-1">@if (! $t || $t->needsReview())<x-badge color="amber">Needs review</x-badge>@elseif ($t->is_no_pay)<x-badge color="red">No-pay</x-badge>@endif</span></td>
                    <td class="whitespace-nowrap px-5 py-3">{{ $l->start_date->format('d M Y') }}@if (! $l->start_date->isSameDay($l->end_date)) – {{ $l->end_date->format('d M Y') }}@endif
                        @if ($l->start_date->isSameDay($l->end_date) && $l->days < 1)<span class="block text-xs text-slate-500">{{ $session[$l->start_session] ?? '' }}</span>@endif</td>
                    <td class="px-5 py-3 text-right">{{ rtrim(rtrim(number_format($l->days, 2), '0'), '.') }}</td>
                    <td class="px-5 py-3">
                        @if ($l->missing_since)<x-badge color="red">Cancelled in MiHCM</x-badge>
                        @elseif ($l->status === 'C')<x-badge color="green">Confirmed</x-badge>
                        @else<x-badge color="amber">Pending</x-badge>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-5 py-8 text-center text-slate-500">No leave in this range.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
