<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('roles.index', [
            'roles' => Role::withCount(['users', 'permissions'])->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('roles.create', ['role' => new Role]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        $role = Role::create([
            'name' => $data['name'], 'guard_name' => 'web',
            'description' => $data['description'] ?? null, 'is_active' => true,
        ]);
        $role->syncPermissions($data['permissions'] ?? []);
        Audit::log('role.permissions_changed', $role, 'Permissions set at creation', null, ['permissions' => collect($data['permissions'] ?? [])->sort()->values()->all()]); // AUDIT

        return redirect()->route('roles.show', $role)->with('status', 'Role created.');
    }

    public function show(Role $role): View
    {
        return view('roles.show', ['role' => $role->load(['permissions', 'users'])]);
    }

    public function edit(Role $role): View|RedirectResponse
    {
        if ($role->isSuperAdmin()) {
            return redirect()->route('roles.show', $role)->with('error', 'Super Admin is a system role and cannot be edited.');
        }

        return view('roles.edit', ['role' => $role->load('permissions')]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->isSuperAdmin()) {
            return back()->with('error', 'Super Admin is a system role and cannot be edited.');
        }

        $data = $request->validate($this->rules($role));
        $role->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $before = $role->permissions()->pluck('name')->sort()->values()->all(); // AUDIT
        $role->syncPermissions($data['permissions'] ?? []);
        $after = collect($data['permissions'] ?? [])->sort()->values()->all(); // AUDIT
        if ($before !== $after) { // AUDIT
            Audit::log('role.permissions_changed', $role, 'Permissions changed', ['permissions' => $before], ['permissions' => $after]); // AUDIT
        }

        return redirect()->route('roles.show', $role)->with('status', 'Role updated.');
    }

    public function toggle(Role $role): RedirectResponse
    {
        if ($role->isSuperAdmin()) {
            return back()->with('error', 'Super Admin cannot be deactivated.');
        }

        $role->update(['is_active' => ! $role->is_active]);

        return back()->with('status', $role->is_active
            ? 'Role activated.'
            : 'Role deactivated. Its permissions no longer apply to any user.');
    }

    private function rules(?Role $role = null): array
    {
        $catalogue = collect(config('permissions'))->flatMap(fn ($g) => array_keys($g))->all();

        return [
            'name'          => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role)],
            'description'   => ['nullable', 'string', 'max:255'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($catalogue)],
        ];
    }
}
