<?php

namespace App\Http\Controllers;

use App\Rules\PolicyPassword;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('password.edit', ['forced' => $request->user()->must_change_password || $request->user()->passwordExpired()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', new PolicyPassword($user)],
        ]);

        if (! PasswordPolicy::canChangeNow($user)) {
            $days = config('password_policy.min_age_days');
            return back()->withErrors(['password' => "Passwords can only be changed once every {$days} day(s). Try again after ".$user->password_changed_at->copy()->addDays($days)->format('d M Y H:i').'.']);
        }

        PasswordPolicy::apply($user, $request->string('password')->toString(), temporary: false);

        return redirect()->route('home')->with('status', 'Your password has been changed.');
    }
}
