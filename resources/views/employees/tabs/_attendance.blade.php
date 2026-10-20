@php
    $rows = $attendance['rows'] ?? collect();
    $sum = $attendance['summary'] ?? ['variables' => [], 'info' => [], 'warnings' => []];
    $labels = \App\Payroll\SystemVariables::all();
    $hm = fn ($m) => sprintf('%d:%02d', intdiv((int) $m, 60), ((int) $m) % 60);
    $time = fn ($t) => $t ? $t->format('H:i') : '—';
    $tabKey = 'attendance';
@endphp
<div x-show="tab === 'attendance'" x-cloak class="space-y-6">
    @include('employees.tabs._att_range')

    <x-card title="Payroll preview for this range">
        <p class="mb-4 text-sm text-slate-500">What payroll would read from this data for {{ $attFrom->format('d M Y') }} – {{ $attTo->format('d M Y') }}. A preview only: a pay run uses its own windows and keeps its own copy when it calculates.</p>
        @foreach ($sum['warnings'] as $w)<x-alert type="warning" class="mb-3">{{ $w }}</x-alert>@endforeach
        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-3 lg:grid-cols-5">
            @foreach (\App\Payroll\AttendanceSummary::VARIABLES as $name)
                <div title="{{ $labels[$name][0] ?? $name }}">
                    <dt class="font-mono text-xs text-slate-500">{{ $name }}</dt>
                    <dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ $sum['variables'][$name] ?? '0' }} <span class="text-xs font-normal text-slate-500">{{ $labels[$name][1] ?? '' }}</span></dd>
                    <p class="text-xs text-slate-500">{{ $labels[$name][0] ?? '' }}</p>
                </div>
            @endforeach
        </dl>
    </x-card>

    <x-card title="Daily attendance" :padding="false">
        <x-table>
            <x-slot:head>
                <tr>
                    <th class="px-5 py-3 font-medium">Date</th><th class="px-5 py-3 font-medium">In – out</th>
                    <th class="px-5 py-3 text-right font-medium">Worked</th><th class="px-5 py-3 text-right font-medium">Late</th>
                    <th class="px-5 py-3 text-right font-medium">Overtime</th><th class="px-5 py-3 font-medium">Leave</th><th class="px-5 py-3 font-medium">Status</th>
                </tr>
            </x-slot:head>
            @forelse ($rows as $r)
                @php $ot = $r->ot_minutes + $r->double_ot_minutes + $r->triple_ot_minutes; @endphp
                <tr @class(['bg-slate-50/60 text-slate-500' => ! $r->expected, 'opacity-60' => $r->missing_since])>
                    <td class="whitespace-nowrap px-5 py-3">{{ $r->work_date->format('d M Y') }}<span class="block text-xs text-slate-500">{{ $r->work_date->format('l') }}@unless ($r->expected) · not expected @endunless</span></td>
                    <td class="whitespace-nowrap px-5 py-3">{{ $time($r->in_time) }} – {{ $time($r->out_time) }}</td>
                    <td class="px-5 py-3 text-right font-mono">{{ $r->work_minutes ? $hm($r->work_minutes) : '—' }}</td>
                    <td class="px-5 py-3 text-right font-mono">{{ $r->late_minutes ? $hm($r->late_minutes) : '—' }}</td>
                    <td class="px-5 py-3 text-right font-mono">{{ $ot ? $hm($ot) : '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $r->leave_name ?? '—' }}</td>
                    <td class="px-5 py-3">
                        <div class="flex flex-wrap gap-1">
                            @if ($r->missing_since)<x-badge color="red">No longer in MiHCM</x-badge>
                            @elseif ($r->expected && ! $r->present)<x-badge color="amber">Absent</x-badge>
                            @elseif ($r->present)<x-badge color="green">Present</x-badge>@endif
                            @if ($ot && ! $r->approval_done)<x-badge color="amber">OT not approved</x-badge>@endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-5 py-8 text-center text-slate-500">No attendance loaded for this range. Use Time &amp; Attendance &gt; Load data.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
