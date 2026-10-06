@props(['name', 'label' => null])
@php $id = $attributes->get('id', $name); $error = $errors->first($name); @endphp
<div class="space-y-1.5">
    @if ($label)<label for="{{ $id }}" class="block text-sm font-medium text-slate-700">{{ $label }}</label>@endif
    <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->except('id')->class([
        'block w-full rounded-md border bg-white py-2 pl-3 pr-8 text-sm text-slate-900 shadow-xs focus:outline-2 focus:-outline-offset-1',
        'border-slate-300 focus:outline-brand-600' => ! $error, 'border-red-400 focus:outline-red-500' => $error,
    ]) }}>{{ $slot }}</select>
    @if ($error)<p class="text-sm text-red-600">{{ $error }}</p>@endif
</div>
