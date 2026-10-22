@extends('layouts.app')
@section('title', $scheme->name.' pay settings')

@section('content')
@php
    $field = 'block w-full rounded-md border border-slate-300 bg-white py-2 px-3 text-sm focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $label = 'block text-sm font-medium text-slate-700';
    $canEdit = auth()->user()->can('schemes.pay-settings');
    $fmt = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M Y');
    $fundLabel = ['epf' => 'EPF', 'msps' => 'MSPS'];
    $formJs = ['cycles' => $form['cycles'], 'windows' => $form['windows'], 'pay_date' => $form['pay_date']];
    foreach ($formJs['windows'] as $k => $w) {
        if (($w['mode'] ?? 'cycle') === 'custom') { // select values are strings, so make these strings too
            $formJs['windows'][$k]['start_offset'] = (string) $w['start_offset'];
            $formJs['windows'][$k]['end_offset'] = (string) $w['end_offset'];
        } else {
            $formJs['windows'][$k] += ['start_day' => '', 'start_offset' => '-1', 'end_day' => '', 'end_offset' => '0'];
        }
    }
    $formJs['pay_date'] += ['month_offset' => '0', 'from_day' => 20, 'to_day' => 30, 'min' => 1, 'max' => 7];
    $formJs['pay_date']['month_offset'] = (string) $formJs['pay_date']['month_offset'];
@endphp
<x-page-header :title="$scheme->name.': pay settings'" description="A scheme is the pay group. These rules decide its pay cycles, the dates attendance is read from, and when it is paid.">
    <x-slot:actions><x-button :href="route('schemes.show', $scheme)" variant="secondary">Back to scheme</x-button></x-slot:actions>
</x-page-header>

@unless ($current)
    <x-alert type="warning" class="mb-4">No pay settings are in force yet. Until you add them, this scheme cannot be used in a pay run.</x-alert>
@endunless

<div class="space-y-6">
    <x-card title="Default provident fund">
        <form method="POST" action="{{ route('schemes.pay.fund', $scheme) }}" class="flex flex-wrap items-end gap-4">
            @csrf @method('PATCH')
            <div class="w-48">
                <label for="default_fund" class="{{ $label }}">Employees of this scheme are</label>
                <select id="default_fund" name="default_fund" class="{{ $field }} mt-1.5" @disabled(! $canEdit)>
                    @foreach ($fundLabel as $k => $t)<option value="{{ $k }}" @selected($scheme->default_fund === $k)>{{ $t }} members</option>@endforeach
                </select>
            </div>
            @if ($canEdit)<x-button type="submit" variant="secondary">Save default</x-button>@endif
            <p class="max-w-xl text-sm text-slate-500">New employees and employees who move into this scheme start on this fund, and a change here is applied to the scheme's employees at once and on every sync. An employee switched by hand on their own page is left alone until you choose "Follow scheme default" there.</p>
        </form>
    </x-card>

    @if ($current)
        <x-card title="In force now">
            <p class="mb-4 text-sm text-slate-500">Since {{ $fmt($current->effective_from) }} · {{ $current->change_note }} · {{ $current->created_by_name ?? 'System' }}</p>
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div class="lg:col-span-3"><dt class="text-slate-500">Pay cycles</dt>
                    <dd class="mt-0.5 font-medium text-slate-900">{{ collect($current->cycles)->map(fn ($c) => \App\Payroll\PayCalendar::describeCycle($c))->implode(' and ') }}</dd></div>
                @foreach (\App\Payroll\PayCalendar::WINDOWS as $k => $t)
                    <div><dt class="text-slate-500">{{ $t }} window</dt><dd class="mt-0.5 font-medium text-slate-900">{{ \App\Payroll\PayCalendar::describeWindow($current->windows[$k] ?? ['mode' => 'cycle']) }}</dd></div>
                @endforeach
                <div><dt class="text-slate-500">Pay date</dt><dd class="mt-0.5 font-medium text-slate-900">{{ \App\Payroll\PayCalendar::describePayDate($current->pay_date) }}</dd></div>
                <div><dt class="text-slate-500">EPF / MSPS / ETF</dt><dd class="mt-0.5 font-medium text-slate-900">{{ \App\Payroll\PayCalendar::TIMINGS[$current->statutory_timing] }}</dd></div>
                <div><dt class="text-slate-500">Paid by</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $current->disbursement === 'cash' ? 'Cash' : 'Bank transfer' }}</dd></div>
            </dl>
        </x-card>

        <x-card title="Periods for a month" :padding="false">
            <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                <label class="text-sm font-medium text-slate-700">Month
                    <input type="month" name="month" value="{{ $month }}" class="{{ $field }} mt-1.5 w-48">
                </label>
                <x-button type="submit" variant="secondary">Show</x-button>
                <p class="pb-2 text-sm text-slate-500">Uses the settings in force in that month. Check the dates here before a pay run relies on them.</p>
            </form>
            @if ($previewVersion)
                <x-table>
                    <x-slot:head>
                        <tr>
                            <th class="px-5 py-3 font-medium">Cycle</th>
                            @foreach (\App\Payroll\PayCalendar::WINDOWS as $t)<th class="px-5 py-3 font-medium">{{ $t }}</th>@endforeach
                            <th class="px-5 py-3 font-medium">Pay date between</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($periods as $p)
                        <tr>
                            <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ $fmt($p['from']) }} – {{ $fmt($p['to']) }}</td>
                            @foreach (array_keys(\App\Payroll\PayCalendar::WINDOWS) as $k)
                                <td class="whitespace-nowrap px-5 py-3 text-xs text-slate-600">{{ $fmt($p['windows'][$k][0]) }} – {{ $fmt($p['windows'][$k][1]) }}</td>
                            @endforeach
                            <td class="whitespace-nowrap px-5 py-3 text-xs text-slate-600">{{ $fmt($p['pay'][0]) }} – {{ $fmt($p['pay'][1]) }}</td>
                        </tr>
                    @endforeach
                </x-table>
            @else
                <p class="px-5 py-6 text-sm text-slate-500">No pay settings were in force in that month.</p>
            @endif
        </x-card>
    @endif

    @if ($canEdit)
        <form method="POST" action="{{ route('schemes.pay.store', $scheme) }}" class="space-y-6" x-data='{ f: @json($formJs), addCycle() { if (this.f.cycles.length < 4) this.f.cycles.push({ start: "", end: null }) }, removeCycle(i) { this.f.cycles.splice(i, 1) } }'>
            @csrf
            @foreach ($errors->get('cycles') as $m)<x-alert type="error">{{ $m }}</x-alert>@endforeach

            <x-card title="{{ $current ? 'New version of the pay settings' : 'Pay settings' }}">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-input name="effective_from" type="date" label="Effective from" :value="old('effective_from', $earliest)" required
                        :hint="$latest ? 'Must be after the latest version ('.$fmt($latest->effective_from).'). Pay runs already made keep the settings they used.' : null" />
                    <x-input name="change_note" label="Reason" :value="old('change_note', $latest ? '' : 'Initial setup')" required />
                </div>
            </x-card>

            <x-card title="Pay cycles">
                <p class="mb-3 text-sm text-slate-500">The cycles must cover the whole month, one after the other. A monthly scheme has one cycle (1st to month end). Leave the end day of the last cycle empty: it always ends at the month end.</p>
                <div class="space-y-3">
                    <template x-for="(c, i) in f.cycles" :key="i">
                        <div class="flex flex-wrap items-end gap-3">
                            <span class="pb-2 text-sm font-medium text-slate-700" x-text="'Cycle ' + (i + 1)"></span>
                            <label class="text-sm text-slate-600">Starts on day
                                <input type="number" min="1" max="28" :name="'cycles[' + i + '][start]'" x-model="c.start" class="{{ $field }} mt-1 w-24"></label>
                            <label class="text-sm text-slate-600">Ends on day
                                <input type="number" min="1" max="27" :name="'cycles[' + i + '][end]'" x-model="c.end" placeholder="month end" class="{{ $field }} mt-1 w-28"></label>
                            <button type="button" x-show="f.cycles.length > 1" @click="removeCycle(i)" class="pb-2 text-xs font-medium text-red-600 hover:underline">Remove</button>
                        </div>
                    </template>
                </div>
                <button type="button" x-show="f.cycles.length < 4" @click="addCycle()" class="mt-3 text-sm font-medium text-brand-700 hover:underline">+ Add a cycle</button>
            </x-card>

            <x-card title="Where attendance is read from">
                <p class="mb-4 text-sm text-slate-500">For each item, use the pay cycle's own dates, or set a different window. A custom window is counted from the month of the cycle: for a pay run of October, "previous month" means September.</p>
                <div class="space-y-5">
                    @foreach (\App\Payroll\PayCalendar::WINDOWS as $k => $t)
                        <div class="rounded-md border border-slate-200 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="text-sm font-semibold text-slate-900">{{ $t }}</p>
                                <select name="windows[{{ $k }}][mode]" x-model="f.windows.{{ $k }}.mode" class="{{ $field }} w-64">
                                    <option value="cycle">Same dates as the pay cycle</option>
                                    <option value="custom">A different window</option>
                                </select>
                            </div>
                            <div x-show="f.windows.{{ $k }}.mode === 'custom'" x-cloak class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <label class="text-sm text-slate-600">Starts on day
                                    <input type="number" min="1" max="31" name="windows[{{ $k }}][start_day]" x-model="f.windows.{{ $k }}.start_day" class="{{ $field }} mt-1"></label>
                                <label class="text-sm text-slate-600">of the
                                    <select name="windows[{{ $k }}][start_offset]" x-model="f.windows.{{ $k }}.start_offset" class="{{ $field }} mt-1">
                                        <option value="-1">previous month</option><option value="0">same month</option></select></label>
                                <label class="text-sm text-slate-600">Ends on day
                                    <input type="number" min="1" max="31" name="windows[{{ $k }}][end_day]" x-model="f.windows.{{ $k }}.end_day" placeholder="month end" class="{{ $field }} mt-1"></label>
                                <label class="text-sm text-slate-600">of the
                                    <select name="windows[{{ $k }}][end_offset]" x-model="f.windows.{{ $k }}.end_offset" class="{{ $field }} mt-1">
                                        <option value="-1">previous month</option><option value="0">same month</option></select></label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>

            <x-card title="Pay date, statutory deductions and payment">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="space-y-3 sm:col-span-2">
                        <label class="{{ $label }}">The pay date is
                            <select name="pay_date[mode]" x-model="f.pay_date.mode" class="{{ $field }} mt-1.5 sm:w-96">
                                <option value="range">Between two days of a month</option>
                                <option value="days_after">A number of days after the cycle ends</option>
                            </select></label>
                        <div x-show="f.pay_date.mode === 'range'" class="grid gap-4 sm:grid-cols-3">
                            <label class="text-sm text-slate-600">Month
                                <select name="pay_date[month_offset]" x-model="f.pay_date.month_offset" class="{{ $field }} mt-1">
                                    <option value="0">the cycle month</option><option value="1">the month after the cycle</option></select></label>
                            <label class="text-sm text-slate-600">From day
                                <input type="number" min="1" max="31" name="pay_date[from_day]" x-model="f.pay_date.from_day" class="{{ $field }} mt-1"></label>
                            <label class="text-sm text-slate-600">To day
                                <input type="number" min="1" max="31" name="pay_date[to_day]" x-model="f.pay_date.to_day" class="{{ $field }} mt-1"></label>
                        </div>
                        <div x-show="f.pay_date.mode === 'days_after'" x-cloak class="grid gap-4 sm:grid-cols-3">
                            <label class="text-sm text-slate-600">At least (days after)
                                <input type="number" min="0" max="31" name="pay_date[min]" x-model="f.pay_date.min" class="{{ $field }} mt-1"></label>
                            <label class="text-sm text-slate-600">At most (days after)
                                <input type="number" min="0" max="31" name="pay_date[max]" x-model="f.pay_date.max" class="{{ $field }} mt-1"></label>
                        </div>
                        <p class="text-sm text-slate-500">The actual pay date is entered on the pay run and must fall inside this range. Days past the end of a short month count as the month end.</p>
                    </div>
                    <div class="space-y-1.5">
                        <label for="statutory_timing" class="{{ $label }}">EPF / MSPS / ETF are deducted</label>
                        <select id="statutory_timing" name="statutory_timing" class="{{ $field }}">
                            @foreach (\App\Payroll\PayCalendar::TIMINGS as $k => $t)<option value="{{ $k }}" @selected($timing === $k)>{{ $t }}</option>@endforeach
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <label for="disbursement" class="{{ $label }}">Paid by</label>
                        <select id="disbursement" name="disbursement" class="{{ $field }}">
                            <option value="bank" @selected($disbursement === 'bank')>Bank transfer</option>
                            <option value="cash" @selected($disbursement === 'cash')>Cash</option>
                        </select>
                    </div>
                </div>
            </x-card>

            <div class="flex justify-end gap-2">
                <x-button type="submit">Save pay settings</x-button>
            </div>
        </form>
    @endif

    <x-card title="History" :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">Effective from</th><th class="px-5 py-3 font-medium">Cycles</th><th class="px-5 py-3 font-medium">Pay date</th>
                    <th class="px-5 py-3 font-medium">EPF / ETF</th><th class="px-5 py-3 font-medium">By</th><th class="px-5 py-3 font-medium">Reason</th></tr>
            </x-slot:head>
            @forelse ($versions as $v)
                <tr>
                    <td class="whitespace-nowrap px-5 py-3 font-medium text-slate-900">{{ $fmt($v->effective_from) }}@if ($current && $v->id === $current->id)<x-badge color="green" class="ml-1">In force</x-badge>@endif</td>
                    <td class="px-5 py-3 text-xs text-slate-600">{{ collect($v->cycles)->map(fn ($c) => \App\Payroll\PayCalendar::describeCycle($c))->implode(' · ') }}</td>
                    <td class="px-5 py-3 text-xs text-slate-600">{{ \App\Payroll\PayCalendar::describePayDate($v->pay_date) }}</td>
                    <td class="px-5 py-3 text-xs text-slate-600">{{ \App\Payroll\PayCalendar::TIMINGS[$v->statutory_timing] }}</td>
                    <td class="px-5 py-3 text-slate-500">{{ $v->created_by_name ?? 'System' }}<span class="block text-xs">{{ $v->created_at->format('d M Y H:i') }}</span></td>
                    <td class="px-5 py-3 text-slate-500">{{ $v->change_note }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-5 py-8 text-center text-slate-500">No pay settings yet.</td></tr>
            @endforelse
        </x-table>
    </x-card>
</div>
@endsection
