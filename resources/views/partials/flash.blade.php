{{-- Success ("status") is shown as a toast by <x-toast-container>. Things the user must act on stay as flat alerts. --}}
@foreach (['warning' => 'warning', 'error' => 'error'] as $key => $type)
    @if (session($key))
        <x-alert :type="$type" :dismissible="true" class="mb-4">{{ session($key) }}</x-alert>
    @endif
@endforeach

@if ($errors->any() && ! session('error'))
    <x-alert type="error" title="Please fix the problems below" class="mb-4">
        {{ $errors->count() }} {{ Str::plural('field', $errors->count()) }} need{{ $errors->count() === 1 ? 's' : '' }} your attention.
    </x-alert>
@endif
