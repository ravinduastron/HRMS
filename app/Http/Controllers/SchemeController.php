<?php

namespace App\Http\Controllers;

use App\Models\Scheme;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Scheme administration is an all-scheme function (routes also require schemes.all). */
class SchemeController extends Controller
{
    public function index(): View
    {
        return view('schemes.index', ['schemes' => Scheme::withCount(['employees', 'users'])->orderBy('name')->get()]);
    }

    public function show(Scheme $scheme): View
    {
        return view('schemes.show', [
            'scheme'    => $scheme->loadCount('employees'),
            'users'     => User::where('is_active', true)->orderBy('name')->get(),
            'memberIds' => $scheme->users()->pluck('users.id')->all(),
        ]);
    }

    public function update(Request $request, Scheme $scheme): RedirectResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'max:100', Rule::unique('schemes', 'name')->ignore($scheme)],
            'description' => ['nullable', 'string', 'max:255'],
            'mihcm_name'  => [$scheme->source === 'mihcm' ? 'required' : 'nullable', 'string', 'max:100', Rule::unique('schemes', 'mihcm_name')->ignore($scheme)],
        ]);

        $scheme->update($data); // Auditable records old and new values

        return back()->with('status', 'Scheme updated.');
    }

    public function members(Request $request, Scheme $scheme): RedirectResponse
    {
        $ids = $request->validate([
            'user_ids'   => ['nullable', 'array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ])['user_ids'] ?? [];

        $before = $scheme->users()->orderBy('name')->pluck('name')->all();
        $scheme->users()->sync($ids);
        $after = $scheme->users()->orderBy('name')->pluck('name')->all();

        if ($before !== $after) {
            Audit::log('scheme.users_changed', $scheme, 'Users allocated to scheme changed', ['users' => $before], ['users' => $after]);
        }

        return back()->with('status', 'Access updated.');
    }
}
