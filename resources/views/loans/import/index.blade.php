@extends('layouts.app')
@section('title', 'Import loans')

@section('content')
@php $color = ['staged' => 'amber', 'applied' => 'green', 'reversed' => 'red', 'discarded' => 'slate']; @endphp
<x-page-header title="Import existing loans" description="Load loans that already exist, with their outstanding balance and remaining installments, from an Excel sheet.">
    <x-slot:actions><x-button :href="route('loans.index')" variant="secondary">All loans</x-button></x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <div class="grid gap-6 lg:grid-cols-2">
        <x-card title="1. Download the template">
            <p class="text-sm text-slate-600">The workbook has an Instructions sheet, the Loans sheet to fill (loan type and interest method are drop-downs) and a Lists sheet with your loan type codes.</p>
            <x-button :href="route('loans.import.template')" variant="secondary" class="mt-4" data-no-loading>Download the Excel template</x-button>
        </x-card>
        <x-card title="2. Upload the filled sheet">
            <form method="POST" action="{{ route('loans.import.upload') }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <input type="file" name="file" accept=".xlsx" required class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-800">
                @error('file')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <x-button type="submit">Upload and check</x-button>
                <p class="text-xs text-slate-500">Up to {{ config('loans.import_max_rows') }} rows. The file is checked row by row first; nothing is created until you confirm.</p>
            </form>
        </x-card>
    </div>

    <x-card title="Uploads" :padding="false">
        <x-table>
            <x-slot:head>
                <tr><th class="px-5 py-3 font-medium">#</th><th class="px-5 py-3 font-medium">File</th><th class="px-5 py-3 font-medium">By</th><th class="px-5 py-3 text-right font-medium">Rows</th>
                    <th class="px-5 py-3 text-right font-medium">Valid</th><th class="px-5 py-3 text-right font-medium">Errors</th><th class="px-5 py-3 text-right font-medium">Loans created</th><th class="px-5 py-3 font-medium">Status</th><th class="px-5 py-3"></th></tr>
            </x-slot:head>
            @forelse ($batches as $b)
                <tr>
                    <td class="px-5 py-3 font-medium text-slate-900">#{{ $b->id }}</td>
                    <td class="px-5 py-3">{{ $b->file_name }}<p class="text-xs text-slate-500">{{ $b->created_at->format('d M Y H:i') }}</p></td>
                    <td class="px-5 py-3 text-slate-600">{{ $b->uploaded_by_name }}</td>
                    <td class="px-5 py-3 text-right">{{ $b->total_rows }}</td><td class="px-5 py-3 text-right">{{ $b->valid_rows }}</td><td class="px-5 py-3 text-right">{{ $b->error_rows }}</td><td class="px-5 py-3 text-right">{{ $b->loans_created }}</td>
                    <td class="px-5 py-3"><x-badge :color="$color[$b->status] ?? 'slate'">{{ ucfirst($b->status) }}</x-badge></td>
                    <td class="px-5 py-3 text-right"><x-button :href="route('loans.import.show', $b)" variant="ghost" size="sm">Open</x-button></td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-5 py-10 text-center text-slate-500">No uploads yet.</td></tr>
            @endforelse
        </x-table>
        @if ($batches->hasPages())<div class="border-t border-slate-200 px-5 py-3">{{ $batches->links() }}</div>@endif
    </x-card>
</div>
@endsection
