@props(['name', 'label'])
<label class="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700">
    <input type="checkbox" name="{{ $name }}" value="1" {{ $attributes->class('size-4 rounded border-slate-300 text-brand-700 focus:ring-brand-600 accent-brand-700') }}>
    {{ $label }}
</label>
