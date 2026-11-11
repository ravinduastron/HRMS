<?php

namespace App\Http\Controllers;

use App\Models\CompanyBankAccount;
use App\Services\CompanyBankAccountService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class CompanyBankAccountController extends Controller
{
    public function __construct(private CompanyBankAccountService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'                => ['required', 'string', 'max:100'],
            'bank_id'             => ['required', 'integer', 'exists:banks,id'],
            'bank_branch_id'      => ['required', 'integer', Rule::exists('bank_branches', 'id')->where('bank_id', $request->input('bank_id'))],
            'account_number'      => ['required', 'string', 'regex:/^[0-9A-Za-z\- ]{6,40}$/', 'confirmed'],
            'account_holder_name' => ['required', 'string', 'max:150'],
        ], ['account_number.regex' => 'Use 6–40 letters or digits (spaces and dashes are ignored).']);

        $this->service->add($data, $request->user());

        return back()->with('status', 'Paying account added.');
    }

    public function makeDefault(CompanyBankAccount $account): RedirectResponse
    {
        return $this->attempt(fn () => $this->service->makeDefault($account), 'Default paying account changed.');
    }

    public function toggle(CompanyBankAccount $account): RedirectResponse
    {
        return $account->is_active
            ? $this->attempt(fn () => $this->service->deactivate($account), 'Account deactivated. It can no longer be chosen for payment.')
            : $this->attempt(fn () => $this->service->activate($account), 'Account activated.');
    }

    public function destroy(Request $request, CompanyBankAccount $account): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->service->remove($account, $request->user(), $request->input('reason')), 'Paying account removed.');
    }

    /** Full number on demand, always logged. */
    public function reveal(CompanyBankAccount $account): JsonResponse
    {
        Audit::log('company_bank_account.revealed', $account, "Full account number viewed (ending {$account->account_last4})");

        return response()->json(['account_number' => $account->account_number])->header('Cache-Control', 'no-store');
    }

    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $success);
    }
}
