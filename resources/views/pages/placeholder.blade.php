@extends('layouts.app')

@section('title', $title)

@section('content')
<x-page-header :title="$title" />
<x-card>
    <p class="text-sm font-medium text-slate-900">This screen hasn't been built yet.</p>
    <p class="mt-1 text-sm text-slate-500">
        Replace the placeholder route for <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">/{{ request()->path() }}</code>
        in <code class="rounded bg-slate-100 px-1 py-0.5 text-xs">routes/web.php</code> with a controller.
    </p>
</x-card>
@endsection
