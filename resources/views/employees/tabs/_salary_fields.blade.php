{{-- Effective date and reason, shared by every salary action popup. The date defaults to the date being viewed. --}}
@php
    $input = 'mt-1.5 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900 focus:outline-2 focus:-outline-offset-1 focus:outline-brand-600';
    $defaultDate = ($salaryDate ?? today())->toDateString();
@endphp
<div class="mt-4 space-y-3 text-left">
    <input type="hidden" name="view_date" value="{{ $defaultDate }}">
    <label class="block text-sm font-medium text-slate-700">Effective from
        <input type="date" name="effective_from" value="{{ $defaultDate }}" required class="{{ $input }}">
    </label>
    <label class="block text-sm font-medium text-slate-700">Reason
        <input type="text" name="change_note" maxlength="500" required autocomplete="off" placeholder="Why is this changing?" class="{{ $input }}">
    </label>
    <p class="text-xs text-slate-500">You can make several changes on the same date. The last one saved is the salary in force.</p>
</div>
