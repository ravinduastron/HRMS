<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAccountPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['username' => 'Your account has been deactivated. Contact your administrator.']);
        }

        if ($user && ! $request->routeIs('password.*', 'logout')) {
            if ($user->must_change_password) {
                return redirect()->route('password.edit')
                    ->with('warning', 'You are using a temporary password. Please set a new password to continue.');
            }
            if ($user->passwordExpired()) {
                return redirect()->route('password.edit')
                    ->with('warning', 'Your password has expired ('.config('password_policy.max_age_days').'-day limit). Please set a new password.');
            }
        }

        return $next($request);
    }
}
