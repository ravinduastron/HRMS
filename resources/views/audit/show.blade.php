@extends('layouts.app')
@section('title', 'Audit entry #'.$log->id)

@section('content')
@php $fields = collect(array_keys(($log->old_values ?? []) + ($log->new_values ?? []))); @endphp
<x-page-header :title="$log->event" :description="$log->occurred_at->format('d M Y H:i:s').' · entry #'.$log->id">
    <x-slot:actions><x-button :href="url()->previous() ?: route('audit.index')" variant="secondary">Back</x-button></x-slot:actions>
</x-page-header>

<div class="space-y-6">
    <x-card title="Summary">
        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div><dt class="text-slate-500">User</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $log->user_name ?? 'System / anonymous' }}</dd></div>
            <div><dt class="text-slate-500">Record</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $log->modelName() }} {{ $log->auditable_id ? '#'.$log->auditable_id : '' }} {{ $log->auditable_label ? '· '.$log->auditable_label : '' }}</dd></div>
            <div><dt class="text-slate-500">IP address</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $log->ip_address ?? '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-slate-500">Request</dt><dd class="mt-0.5 break-all font-medium text-slate-900">{{ $log->method }} {{ $log->url ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Integrity</dt><dd class="mt-0.5">
                @if ($log->checksumIsValid())<x-badge color="green">Verified</x-badge>@else<x-badge color="red">Checksum mismatch</x-badge>@endif</dd></div>
            @if ($log->description)<div class="sm:col-span-3"><dt class="text-slate-500">Description</dt><dd class="mt-0.5 text-slate-900">{{ $log->description }}</dd></div>@endif
            @if ($log->context)<div class="sm:col-span-3"><dt class="text-slate-500">Context</dt><dd class="mt-0.5"><code class="break-all text-xs">{{ json_encode($log->context, JSON_UNESCAPED_UNICODE) }}</code></dd></div>@endif
        </dl>
    </x-card>

    @if ($fields->isNotEmpty())
        <x-card title="Changes" :padding="false">
            <x-table>
                <x-slot:head><tr><th class="px-5 py-3 font-medium">Field</th><th class="px-5 py-3 font-medium">Before</th><th class="px-5 py-3 font-medium">After</th></tr></x-slot:head>
                @foreach ($fields as $f)
                    <tr>
                        <td class="px-5 py-3 font-medium text-slate-900">{{ $f }}</td>
                        <td class="px-5 py-3 align-top">@include('audit._value', ['v' => $log->old_values[$f] ?? null])</td>
                        <td class="px-5 py-3 align-top">@include('audit._value', ['v' => $log->new_values[$f] ?? null])</td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    @endif

    @if ($related->isNotEmpty())
        <x-card title="Other entries from the same request">
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach ($related as $r)
                    <li class="flex items-center justify-between py-2">
                        <span><span class="font-medium">{{ $r->event }}</span> <span class="text-slate-500">· {{ $r->modelName() }} {{ $r->auditable_label ?? $r->auditable_id }}</span></span>
                        <a href="{{ route('audit.show', $r) }}" class="text-brand-700 hover:underline">Details</a>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
</div>
@endsection
