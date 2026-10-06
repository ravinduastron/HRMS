<?php

namespace App\Rules;

use App\Models\User;
use App\Support\PasswordPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PolicyPassword implements ValidationRule
{
    /** Pass $user for existing accounts (enables history check) or name/username for new ones. */
    public function __construct(
        protected ?User $user = null,
        protected ?string $name = null,
        protected ?string $username = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;
        $min = config('password_policy.min_length');

        if (mb_strlen($value) < $min) {
            $fail("The :attribute must be at least {$min} characters.");
        }
        if (! preg_match('/[A-Z]/', $value)) {
            $fail('The :attribute must contain an uppercase letter (A-Z).');
        }
        if (! preg_match('/[a-z]/', $value)) {
            $fail('The :attribute must contain a lowercase letter (a-z).');
        }
        if (! preg_match('/[0-9]/', $value)) {
            $fail('The :attribute must contain a number (0-9).');
        }
        if (! preg_match('/[^A-Za-z0-9]/', $value)) {
            $fail('The :attribute must contain a special character (for example $, #, !, %).');
        }

        $parts = PasswordPolicy::forbiddenParts($this->name ?? $this->user?->name, $this->username ?? $this->user?->username);
        foreach ($parts as $part) {
            if (str_contains(mb_strtolower($value), $part)) {
                $fail("The :attribute must not contain any part of the name or username (\"{$part}\").");
                break;
            }
        }

        if ($this->user && PasswordPolicy::wasUsedBefore($this->user, $value)) {
            $n = config('password_policy.history');
            $fail("The :attribute cannot be the same as any of your last {$n} passwords.");
        }
    }
}
