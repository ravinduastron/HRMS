<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class AstronEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = Str::lower(trim((string) $value));
        $domain = config('accounts.email_domain');

        if (! Str::endsWith($email, '@'.$domain)) {
            $fail("The :attribute must be an @{$domain} address.");
        }

        foreach (config('accounts.blocked_email_terms') as $term) {
            if (str_contains($email, Str::lower($term))) {
                $fail("The :attribute must not contain \"{$term}\".");
                break;
            }
        }
    }
}
