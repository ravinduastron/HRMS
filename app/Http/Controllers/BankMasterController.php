<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Banks and branches: add and remove only (no editing). A bank/branch used by any account, even a removed one, cannot be removed. */
class BankMasterController extends Controller
{
    public function index(): View
    {
        return view('banks.index', [
            'banks' => Bank::withCount(['branches', 'accounts' => fn ($q) => $q->withTrashed()])->orderBy('name')->get(),
        ]);
    }

    public function show(Bank $bank): View
    {
        return view('banks.show', [
            'bank'     => $bank,
            'branches' => $bank->branches()->withCount(['accounts' => fn ($q) => $q->withTrashed()])->get(),
            'inUse'    => BankAccount::withTrashed()->where('bank_id', $bank->id)->exists(),
        ]);
    }

    public function storeBank(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'regex:/^[0-9A-Za-z]+$/', 'unique:banks,code'],
            'name' => ['required', 'string', 'max:150', 'unique:banks,name'],
        ]);
        $bank = Bank::create(['code' => strtoupper($data['code']), 'name' => trim($data['name'])]);

        return redirect()->route('banks.show', $bank)->with('status', 'Bank added. Now add its branches.');
    }

    public function destroyBank(Bank $bank): RedirectResponse
    {
        if (BankAccount::withTrashed()->where('bank_id', $bank->id)->exists()) {
            return back()->with('error', "{$bank->name} is used by employee bank accounts (including removed ones), so it cannot be removed.");
        }

        DB::transaction(function () use ($bank) {
            $bank->branches->each->delete(); // one audit entry per branch
            $bank->delete();
        });

        return redirect()->route('banks.index')->with('status', 'Bank removed.');
    }

    public function storeBranch(Request $request, Bank $bank): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'regex:/^[0-9A-Za-z]+$/', Rule::unique('bank_branches', 'code')->where('bank_id', $bank->id)],
            'name' => ['required', 'string', 'max:150', Rule::unique('bank_branches', 'name')->where('bank_id', $bank->id)],
        ]);
        $bank->branches()->create(['code' => strtoupper($data['code']), 'name' => trim($data['name'])]);

        return back()->with('status', 'Branch added.');
    }

    public function destroyBranch(Bank $bank, BankBranch $branch): RedirectResponse
    {
        abort_unless($branch->bank_id === $bank->id, 404);

        if (BankAccount::withTrashed()->where('bank_branch_id', $branch->id)->exists()) {
            return back()->with('error', "{$branch->name} is used by employee bank accounts (including removed ones), so it cannot be removed.");
        }
        $branch->delete();

        return back()->with('status', 'Branch removed.');
    }
}
