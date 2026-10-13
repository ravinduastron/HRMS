<?php

namespace App\Http\Controllers;

use App\Models\PayComponent;
use App\Models\PayComponentGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PayComponentGroupController extends Controller
{
    public function index(): View
    {
        return view('payroll.groups.index', [
            'groups' => PayComponentGroup::withCount(['components', 'components as active_components_count' => fn ($q) => $q->where('is_active', true)])
                ->orderBy('type')->orderBy('payslip_order')->orderBy('name')->get(),
            'group' => new PayComponentGroup(['payslip_order' => 100, 'type' => 'earning']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());

        PayComponentGroup::create([
            'name' => trim($data['name']), 'type' => $data['type'], 'description' => $data['description'] ?? null,
            'payslip_order' => $data['payslip_order'], 'combine_on_payslip' => $request->boolean('combine_on_payslip'), 'is_active' => true,
        ]);

        return redirect()->route('pay-component-groups.index')->with('status', 'Group added.');
    }

    public function edit(PayComponentGroup $group): View
    {
        return view('payroll.groups.edit', ['group' => $group->loadCount('components')]);
    }

    public function update(Request $request, PayComponentGroup $group): RedirectResponse
    {
        $typeLocked = $group->components()->exists(); // the type of a group in use cannot change
        $data = $request->validate($this->rules($group, $typeLocked));

        $group->update([
            'name' => trim($data['name']), 'type' => $typeLocked ? $group->type : $data['type'],
            'description' => $data['description'] ?? null, 'payslip_order' => $data['payslip_order'],
            'combine_on_payslip' => $request->boolean('combine_on_payslip'),
        ]);

        return redirect()->route('pay-component-groups.index')->with('status', 'Group updated.');
    }

    public function toggle(PayComponentGroup $group): RedirectResponse
    {
        if ($group->is_active && $group->components()->where('is_active', true)->exists()) {
            return back()->with('error', "{$group->name} still has active components. Move or deactivate them first.");
        }

        $group->update(['is_active' => ! $group->is_active]);

        return back()->with('status', $group->is_active ? 'Group activated.' : 'Group deactivated.');
    }

    private function rules(?PayComponentGroup $group = null, bool $typeLocked = false): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('pay_component_groups', 'name')->ignore($group)],
            'type' => $typeLocked ? ['nullable'] : ['required', Rule::in(array_keys(PayComponent::TYPES))],
            'description' => ['nullable', 'string', 'max:255'],
            'payslip_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
