@foreach (['status' => 'success', 'warning' => 'warning', 'error' => 'error'] as $key => $type)
    @if (session($key))
        <x-alert :type="$type" :dismissible="true" class="mb-4">{{ session($key) }}</x-alert>
    @endif
@endforeach
