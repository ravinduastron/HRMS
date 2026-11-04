<?php

namespace App\Http\Controllers;

use App\Models\HirePurchaseVendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Vendors employees buy from. Contact details only. Vendors are deactivated, never deleted. Viewing needs hire-purchases.view, changing needs hire-purchase-vendors.manage. */
class HirePurchaseVendorController extends Controller
{
    public function index(): View
    {
        return view('hire-purchases.vendors.index', [
            'vendors' => HirePurchaseVendor::withCount(['purchases', 'purchases as active_purchases_count' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'active')])->orderBy('name')->get(),
            'vendor' => new HirePurchaseVendor,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $vendor = HirePurchaseVendor::create($this->attributes($data) + ['code' => strtoupper($data['code']), 'is_active' => true]);

        return redirect()->route('hire-purchase-vendors.index')->with('status', "Vendor {$vendor->code} added.");
    }

    public function edit(HirePurchaseVendor $vendor): View
    {
        return view('hire-purchases.vendors.edit', ['vendor' => $vendor]);
    }

    public function update(Request $request, HirePurchaseVendor $vendor): RedirectResponse
    {
        $data = $request->validate($this->rules($vendor));
        $vendor->update($this->attributes($data)); // Auditable records old and new values

        return redirect()->route('hire-purchase-vendors.index')->with('status', 'Vendor updated.');
    }

    public function toggle(HirePurchaseVendor $vendor): RedirectResponse
    {
        $vendor->update(['is_active' => ! $vendor->is_active]);

        return back()->with('status', $vendor->is_active ? 'Vendor activated.' : 'Vendor deactivated. No new hire purchase can use it; existing ones are not affected.');
    }

    private function rules(?HirePurchaseVendor $vendor = null): array
    {
        return [
            'code' => $vendor ? ['nullable'] : ['required', 'string', 'regex:/^[A-Za-z][A-Za-z0-9_-]{1,29}$/', 'unique:hire_purchase_vendors,code'],
            'name' => ['required', 'string', 'max:150', Rule::unique('hire_purchase_vendors', 'name')->ignore($vendor)],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function attributes(array $d): array
    {
        return [
            'name' => trim($d['name']), 'contact_person' => $d['contact_person'] ?? null, 'phone' => $d['phone'] ?? null,
            'email' => isset($d['email']) ? strtolower($d['email']) : null, 'address' => $d['address'] ?? null,
        ];
    }
}
