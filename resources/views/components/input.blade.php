{{-- Text input with label, leading icon, hint, validation error, and show/hide for passwords. --}}
@props(['name', 'label' => null, 'type' => 'text', 'icon' => null, 'hint' => null])
@php
    $id = $attributes->get('id', $name);
    $error = $errors->first($name);
    $isPassword = $type === 'password';
@endphp
<div x-data="{ show: false }" class="space-y-1.5">
    @if ($label)
        <label for="{{ $id }}" class="block text-sm font-medium text-slate-700">{{ $label }}</label>
    @endif
    <div class="relative">
        @if ($icon)
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <x-icon :name="$icon" class="size-5" />
            </span>
        @endif
        <input id="{{ $id }}" name="{{ $name }}"
            @if ($isPassword) type="password" :type="show ? 'text' : 'password'" @else type="{{ $type }}" @endif
            @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
            {{ $attributes->except('id')->class([
                'block w-full rounded-md border bg-white py-2 text-sm text-slate-900 shadow-xs placeholder:text-slate-400 focus:outline-2 focus:-outline-offset-1',
                'pl-10' => $icon, 'pl-3' => ! $icon,
                'pr-10' => $isPassword, 'pr-3' => ! $isPassword,
                'border-slate-300 focus:outline-brand-600' => ! $error,
                'border-red-400 focus:outline-red-500' => $error,
            ]) }}>
        @if ($isPassword)
            <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"
                :aria-label="show ? 'Hide password' : 'Show password'">
                <x-icon name="eye" x-show="!show" class="size-5" />
                <x-icon name="eye-slash" x-show="show" x-cloak class="size-5" />
            </button>
        @endif
    </div>
    @if ($error)
        <div id="{{ $id }}-error" class="space-y-0.5 text-sm text-red-600">
            @foreach ($errors->get($name) as $message)<p>{{ $message }}</p>@endforeach
        </div>
    @elseif ($hint)
        <p class="text-sm text-slate-500">{{ $hint }}</p>
    @endif
</div>
