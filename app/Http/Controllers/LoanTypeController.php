<?php

namespace App\Http\Controllers;

use App\Models\LoanType;
use App\Models\PayComponent;
use App\Payroll\Loans\LoanSchedule;
use App\Payroll\Loans\LoanSettlement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LoanTypeController extends Controller
{
    public function index(): View
    {
        return view('loans.types.index', ['types' => LoanType::withCount('loans')->with(['capitalComponent', 'interestComponent'])->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('loans.types.form', $this->formData(new LoanType(['interest_method' => 'declining', 'default_rate' => 0, 'settlement_interest_rule' => 'full', 'is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $type = LoanType::create($this->attributes($data) + ['code' => strtoupper($data['code']), 'is_active' => true]);

        return redirect()->route('loan-types.index')->with('status', "Loan type {$type->code} created.");
    }

    public function edit(LoanType $type): View
    {
        return view('loans.types.form', $this->formData($type));
    }

    public function update(Request $request, LoanType $type): RedirectResponse
    {
        $data = $request->validate($this->rules($type));
        $type->update($this->attributes($data)); // Auditable records old and new values; existing loans keep their own method and settlement rule

        return redirect()->route('loan-types.index')->with('status', 'Loan type updated. Loans already created keep their own method and settlement rule.');
    }

    public function toggle(LoanType $type): RedirectResponse
    {
        $type->update(['is_active' => ! $type->is_active]);

        return back()->with('status', $type->is_active ? 'Loan type activated.' : 'Loan type deactivated. No new loans can use it; existing loans are not affected.');
    }

    private function rules(?LoanType $type = null): array
    {
        $component = Rule::exists('pay_components', 'id')->where('type', 'deduction')->where('is_active', true);

        return [
            'code' => $type ? ['nullable'] : ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_]{1,29}$/', 'unique:loan_types,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'interest_method' => ['required', Rule::in(array_keys(LoanSchedule::METHODS))],
            'default_rate' => ['required', 'numeric', 'between:0,100', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'max_term_months' => ['nullable', 'integer', 'between:1,600'],
            'max_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'settlement_interest_rule' => ['required', Rule::in(array_keys(LoanSettlement::RULES))],
            'capital_component_id' => ['required', 'integer', $component],
            'interest_component_id' => ['required', 'integer', $component, 'different:capital_component_id'],
        ];
    }

    private function attributes(array $d): array
    {
        return [
            'name' => trim($d['name']), 'description' => $d['description'] ?? null, 'interest_method' => $d['interest_method'], 'default_rate' => $d['default_rate'],
            'max_term_months' => $d['max_term_months'] ?? null, 'max_amount' => $d['max_amount'] ?? null, 'settlement_interest_rule' => $d['settlement_interest_rule'],
            'capital_component_id' => $d['capital_component_id'], 'interest_component_id' => $d['interest_component_id'],
        ];
    }

    private function formData(LoanType $type): array
    {
        return [
            'type' => $type,
            'components' => PayComponent::where('type', 'deduction')->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_filter([$type->capital_component_id, $type->interest_component_id])))
                ->orderBy('code')->get()->map(fn ($c) => ['value' => $c->id, 'label' => $c->code.' · '.$c->name])->values(),
        ];
    }
}
