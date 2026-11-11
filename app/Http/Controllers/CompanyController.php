<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\CompanyBankAccount;
use App\Models\CompanyProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Company profile and paying accounts. Viewing needs company.view; changing also needs schemes.all (the company is global). */
class CompanyController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $canAccounts = $user->can('company-bank-accounts.manage') && $user->can('schemes.all');

        return view('company.index', [
            'profile'  => CompanyProfile::current(),
            'accounts' => CompanyBankAccount::with(['bank', 'branch'])->orderByDesc('is_default')->orderByDesc('is_active')->orderBy('name')->get(),
            'banks'    => $canAccounts ? Bank::with('branches')->orderBy('name')->get() : collect(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'legal_name'      => ['required', 'string', 'max:150'],
            'trading_name'    => ['nullable', 'string', 'max:150'],
            'registration_no' => ['nullable', 'string', 'max:50'],
            'tin'             => ['nullable', 'string', 'max:30'],
            'address'         => ['nullable', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'email'           => ['nullable', 'email:rfc', 'max:255'],
            'epf_employer_no' => ['nullable', 'string', 'max:30'],
            'etf_employer_no' => ['nullable', 'string', 'max:30'],
        ]);

        CompanyProfile::current()->fill($data)->save(); // Auditable records old and new values

        return back()->with('status', 'Company details saved.');
    }
}
