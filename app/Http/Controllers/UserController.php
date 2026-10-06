<?php

namespace App\Http\Controllers;

use App\Mail\TemporaryPasswordMail;
use App\Models\Role;
use App\Models\User;
use App\Rules\AstronEmail;
use App\Rules\PolicyPassword;
use App\Support\Audit;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()->with('roles')
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")->orWhere('username', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->when($request->query('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->query('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')->paginate(15)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        return view('users.create', ['user' => new User, 'roles' => Role::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            ...$this->baseRules(),
            'password' => ['required', 'string', new PolicyPassword(null, $request->input('name'), $request->input('username'))],
        ]);
        $roles = $this->resolveRoles($data['roles'], $request);

        $user = new User;
        $user->forceFill([
            'name'      => $data['name'],
            'username'  => $data['username'],
            'email'     => strtolower($data['email']),
            'is_active' => true,
            'password'  => $data['password'],
        ])->save();
        PasswordPolicy::apply($user, $data['password'], temporary: true);
        $user->syncRoles($roles);
        Audit::log('user.roles_changed', $user, 'Roles assigned at creation', null, ['roles' => $roles->pluck('name')->sort()->values()->all()]); // AUDIT
        Audit::log('user.credentials_emailed', $user, 'Temporary password emailed'); // AUDIT

        $sent = $this->sendCredentials($user, $data['password'], 'created');

        return redirect()->route('users.show', $user)->with(
            $sent ? 'status' : 'warning',
            $sent ? "User created. Login details were emailed to {$user->email}."
                  : 'User created, but the email could not be sent. Use "Reset password" to send credentials again.',
        );
    }

    public function show(User $user): View
    {
        return view('users.show', ['user' => $user->load('roles.permissions')]);
    }

    public function edit(User $user): View
    {
        return view('users.edit', ['user' => $user->load('roles'), 'roles' => Role::orderBy('name')->get()]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate($this->baseRules($user));
        $roles = $this->resolveRoles($data['roles'], $request);

        $user->forceFill([
            'name' => $data['name'], 'username' => $data['username'], 'email' => strtolower($data['email']),
        ])->save();
        $before = $user->roles()->pluck('name')->sort()->values()->all(); // AUDIT
        $user->syncRoles($roles);
        $after = $roles->pluck('name')->sort()->values()->all(); // AUDIT
        if ($before !== $after) { // AUDIT
            Audit::log('user.roles_changed', $user, 'Roles changed', ['roles' => $before], ['roles' => $after]); // AUDIT
        }

        return redirect()->route('users.show', $user)->with('status', 'User updated.');
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot deactivate your own account.');
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();
        if ($user->is_active) {
            $user->clearFailedLogins();
        }

        Audit::log($user->is_active ? 'user.activated' : 'user.deactivated', $user); // AUDIT

        return back()->with('status', $user->is_active ? 'User activated.' : 'User deactivated. They can no longer sign in.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'Use "Change password" to change your own password.');
        }

        $plain = PasswordPolicy::generate($user->name, $user->username);
        PasswordPolicy::apply($user, $plain, temporary: true);
        Audit::log('user.password_reset', $user, 'Password reset by administrator; temporary password emailed'); // AUDIT

        $sent = $this->sendCredentials($user, $plain, 'reset');

        return back()->with(
            $sent ? 'status' : 'warning',
            $sent ? "Password reset. A temporary password was emailed to {$user->email}; they must change it at next sign-in."
                  : 'Password was reset but the email could not be sent. Please try again.',
        );
    }

    private function baseRules(?User $user = null): array
    {
        return [
            'name'     => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user)],
            'email'    => ['required', 'string', 'email:rfc', 'max:255', new AstronEmail, Rule::unique('users', 'email')->ignore($user)],
            'roles'    => ['required', 'array', 'min:1'],
            'roles.*'  => ['integer', Rule::exists('roles', 'id')],
        ];
    }

    /** Only Super Admins may hand out the Super Admin role (prevents privilege escalation). */
    private function resolveRoles(array $ids, Request $request)
    {
        $roles = Role::whereIn('id', $ids)->get();

        if ($roles->contains(fn ($r) => $r->isSuperAdmin()) && ! $request->user()->hasRole(Role::SUPER_ADMIN)) {
            throw ValidationException::withMessages(['roles' => 'Only a Super Admin can assign the Super Admin role.']);
        }

        return $roles;
    }

    private function sendCredentials(User $user, string $plain, string $reason): bool
    {
        try {
            Mail::to($user->email)->send(new TemporaryPasswordMail($user, $plain, $reason));
            return true;
        } catch (\Throwable $e) {
            Log::error('Credential email failed: '.$e->getMessage(), ['user_id' => $user->id]);
            return false;
        }
    }
}
