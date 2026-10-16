<?php

namespace App\Http\Controllers;

use App\Models\LeaveEntry;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Leave types are created by the load. An administrator decides which are no-pay; undecided ones will block payroll approval. */
class LeaveTypeController extends Controller
{
    public function index(): View
    {
        return view('leave.types.index', [
            'types' => LeaveType::orderByRaw('is_no_pay is not null')->orderBy('name')->get(),
            'entries' => LeaveEntry::withoutGlobalScopes()->selectRaw('leave_type_code, count(*) as total')->groupBy('leave_type_code')->pluck('total', 'leave_type_code'),
        ]);
    }

    public function update(Request $request, LeaveType $type): RedirectResponse
    {
        $data = $request->validate(['is_no_pay' => ['required', 'in:0,1']]);
        $type->update(['is_no_pay' => $data['is_no_pay'] === '1']); // Auditable records the change

        return back()->with('status', "{$type->name} is now treated as ".($type->is_no_pay ? 'no-pay' : 'paid').' leave.');
    }
}
