{{-- Drop on any detail page:  @can('audit.view') <x-audit-timeline :model="$employee" /> @endcan --}}
@props(['model', 'limit' => 10])
@php $entries = \App\Models\AuditLog::forModel($model)->latest('occurred_at')->latest('id')->limit($limit)->get(); @endphp
<x-card title="Change history">
    @forelse ($entries as $e)
        <div class="flex items-start justify-between gap-4 border-b border-slate-100 py-2.5 text-sm last:border-0">
            <div>
                <span class="font-medium text-slate-900">{{ $e->event }}</span>
                <span class="text-slate-500">by {{ $e->user_name ?? 'system' }}</span>
                @if ($e->new_values)<p class="mt-0.5 text-xs text-slate-500">Fields: {{ implode(', ', array_keys($e->new_values)) }}</p>@endif
                @if ($e->description)<p class="mt-0.5 text-xs text-slate-500">{{ $e->description }}</p>@endif
            </div>
            <div class="shrink-0 text-right text-xs text-slate-500">
                <time title="{{ $e->occurred_at->format('d M Y H:i:s') }}">{{ $e->occurred_at->diffForHumans() }}</time><br>
                <a href="{{ route('audit.show', $e) }}" class="text-brand-700 hover:underline">Details</a>
            </div>
        </div>
    @empty
        <p class="text-sm text-slate-500">No recorded changes yet.</p>
    @endforelse
</x-card>
