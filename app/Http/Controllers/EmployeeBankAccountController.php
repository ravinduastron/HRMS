<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Employee;
use App\Services\BankAccountService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class EmployeeBankAccountController extends Controller
{
    public function __construct(private BankAccountService $service) {}

    public function store(Request $request, Employee $employee): RedirectResponse
    {
        $data = $request->validate([
            'bank_id'             => ['required', 'integer', 'exists:banks,id'],
            'bank_branch_id'      => ['required', 'integer', Rule::exists('bank_branches', 'id')->where('bank_id', $request->input('bank_id'))],
            'account_number'      => ['required', 'string', 'regex:/^[0-9A-Za-z\- ]{6,40}$/', 'confirmed'],
            'account_holder_name' => ['required', 'string', 'max:150'],
        ], ['account_number.regex' => 'Use 6–40 letters or digits (spaces and dashes are ignored).']);

        $this->service->add($employee, $data, $request->user());

        return $this->back($employee)->with('status', 'Bank account added.');
    }

    public function activate(Request $request, Employee $employee, BankAccount $account): RedirectResponse
    {
        $this->owned($employee, $account);

        try {
            $this->service->activate($account);
        } catch (RuntimeException $e) {
            return $this->back($employee)->with('error', $e->getMessage());
        }

        return $this->back($employee)->with('status', 'Active bank account changed.');
    }

    public function destroy(Request $request, Employee $employee, BankAccount $account): RedirectResponse
    {
        $this->owned($employee, $account);
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $wasActive = $this->service->remove($account, $request->user(), $request->input('reason'));

        $redirect = $this->back($employee);

        return $wasActive && $employee->bankAccounts()->exists()
            ? $redirect->with('warning', 'The active account was removed. Activate the other account before the next payroll run.')
            : $redirect->with('status', 'Bank account removed.');
    }

    /** Full number on demand, always logged. */
    public function reveal(Request $request, Employee $employee, BankAccount $account): JsonResponse
    {
        $this->owned($employee, $account);
        Audit::log('bank_account.revealed', $account, "Full account number viewed (ending {$account->account_last4})", context: ['employee_id' => $employee->id]);

        return response()->json(['account_number' => $account->account_number])->header('Cache-Control', 'no-store');
    }

    private function owned(Employee $employee, BankAccount $account): void
    {
        abort_unless($account->employee_id === $employee->id, 404);
    }

    private function back(Employee $employee): RedirectResponse
    {
        return redirect()->route('employees.show', [$employee, 'tab' => 'bank']);
    }
}
