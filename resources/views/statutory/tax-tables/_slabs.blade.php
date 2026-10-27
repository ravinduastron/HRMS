{{-- Vars: $slabs (plain arrays), $lump (bool: wording) --}}
<x-table>
    <x-slot:head>
        <tr>
            <th class="px-5 py-3 text-right font-medium">{{ $lump ? 'Estimated yearly pay (D) over (Rs)' : 'Monthly taxable income over (Rs)' }}</th>
            <th class="px-5 py-3 text-right font-medium">Up to and including (Rs)</th>
            <th class="px-5 py-3 text-right font-medium">Rate</th>
            <th class="px-5 py-3 text-right font-medium">{{ $lump ? 'Fixed amount (Rs)' : 'Less (Rs)' }}</th>
        </tr>
    </x-slot:head>
    @foreach ($slabs as $s)
        <tr>
            <td class="px-5 py-3 text-right font-mono">{{ \App\Payroll\Statutory\Format::money($s['over'], 0) }}</td>
            <td class="px-5 py-3 text-right font-mono">{{ $s['up_to'] === null ? 'no limit' : \App\Payroll\Statutory\Format::money($s['up_to'], 0) }}</td>
            <td class="px-5 py-3 text-right font-mono">{{ $s['rate'] }}%</td>
            <td class="px-5 py-3 text-right font-mono">{{ \App\Payroll\Statutory\Format::money($s['fixed'], 0) }}</td>
        </tr>
    @endforeach
</x-table>
