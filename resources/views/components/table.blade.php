{{-- <x-table><x-slot:head><tr><th class="px-4 py-3 font-medium">Name</th></tr></x-slot:head> <tr><td class="px-4 py-3">…</td></tr></x-table> --}}
<div {{ $attributes->class('overflow-x-auto') }}>
    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
        <thead class="bg-slate-50 text-xs text-slate-500">{{ $head }}</thead>
        <tbody class="divide-y divide-slate-100 text-slate-700">{{ $slot }}</tbody>
    </table>
</div>
