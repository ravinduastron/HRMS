<x-mail::message>
# Hello {{ $user->name }},

@if ($reason === 'reset')
Your {{ config('payroll.name') }} password has been reset by an administrator.
@else
An account has been created for you on {{ config('payroll.name') }}.
@endif

<x-mail::panel>
**Username:** {{ $user->username }}<br>
**Temporary password:** {{ $plainPassword }}
</x-mail::panel>

This is a temporary password. You will be asked to choose a new one the first time you sign in.

<x-mail::button :url="route('login')">
Sign in
</x-mail::button>

If you did not expect this email, please contact your administrator.

Thanks,<br>
{{ config('payroll.company') }}
</x-mail::message>
