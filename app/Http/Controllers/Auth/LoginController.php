<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $credentials['username'])->first();

        if ($user?->isLocked()) {
            Audit::log('auth.login_blocked', $user, 'Sign-in refused: account locked', actor: $user); // AUDIT
            $minutes = (int) ceil(now()->diffInSeconds($user->locked_until, true) / 60);
            throw ValidationException::withMessages([
                'username' => "This account is temporarily locked after too many failed attempts. Try again in {$minutes} minute(s).",
            ]);
        }

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $user?->registerFailedLogin();
            Audit::log('auth.login_failed', $user, 'Invalid username or password', context: ['username' => $credentials['username']], actor: $user); // AUDIT
            if ($user?->isLocked()) {
                Audit::log('auth.account_locked', $user, 'Account locked after repeated failures', actor: $user); // AUDIT
            }
            throw ValidationException::withMessages(['username' => 'The username or password is incorrect.']);
        }

        if (! $user->is_active) {
            Audit::log('auth.login_blocked', $user, 'Sign-in refused: account deactivated', actor: $user); // AUDIT
            throw ValidationException::withMessages(['username' => 'Your account has been deactivated. Contact your administrator.']);
        }

        $user->clearFailedLogins();
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
