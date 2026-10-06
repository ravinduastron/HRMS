<?php

namespace App\Support;

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PasswordPolicy
{
    /** Name/username fragments (3+ chars, case-insensitive) a password must not contain. */
    public static function forbiddenParts(?string $name, ?string $username): array
    {
        $parts = preg_split('/[^\pL\pN]+/u', (string) $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($username) {
            $parts[] = $username;
            $parts = array_merge($parts, preg_split('/[^\pL\pN]+/u', $username, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        return collect($parts)->map(fn ($p) => mb_strtolower($p))
            ->filter(fn ($p) => mb_strlen($p) >= 3)->unique()->values()->all();
    }

    public static function generate(?string $name = null, ?string $username = null): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%^&*?'];
        $all = implode('', $sets);
        $length = config('password_policy.generated_length');
        $forbidden = self::forbiddenParts($name, $username);

        do {
            $chars = array_map(fn ($s) => $s[random_int(0, strlen($s) - 1)], $sets);
            while (count($chars) < $length) {
                $chars[] = $all[random_int(0, strlen($all) - 1)];
            }
            for ($i = count($chars) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
            }
            $password = implode('', $chars);
            $clash = collect($forbidden)->contains(fn ($p) => str_contains(mb_strtolower($password), $p));
        } while ($clash);

        return $password;
    }

    public static function wasUsedBefore(User $user, string $plain): bool
    {
        if ($user->password && Hash::check($plain, $user->password)) {
            return true;
        }

        return $user->passwordHistories()->latest('id')->limit(config('password_policy.history'))
            ->pluck('password')->contains(fn ($hash) => Hash::check($plain, $hash));
    }

    /** Minimum age applies to voluntary changes only (not forced, expired, or admin-reset passwords). */
    public static function canChangeNow(User $user): bool
    {
        if ($user->must_change_password || $user->passwordExpired() || ! $user->password_changed_at) {
            return true;
        }

        return $user->password_changed_at->copy()->addDays(config('password_policy.min_age_days'))->isPast();
    }

    public static function apply(User $user, string $plain, bool $temporary): void
    {
        DB::transaction(function () use ($user, $plain, $temporary) {
            $user->forceFill([
                'password'              => $plain, // bcrypt via the model's "hashed" cast
                'must_change_password'  => $temporary,
                'password_changed_at'   => now(),
                'failed_login_attempts' => 0,
                'last_failed_login_at'  => null,
                'locked_until'          => null,
            ])->save();

            PasswordHistory::create(['user_id' => $user->id, 'password' => $user->password]);

            $keep = PasswordHistory::where('user_id', $user->id)->latest('id')
                ->limit(config('password_policy.history'))->pluck('id');
            PasswordHistory::where('user_id', $user->id)->whereNotIn('id', $keep)->delete();
        });
    }
}
