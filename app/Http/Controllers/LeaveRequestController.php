<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveEntry;
use App\Models\LeaveType;
use App\Models\Scheme;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/** Leave as loaded from MiHCM (read-only). Scheme restrictions apply automatically. */
class LeaveRequestController extends Controller
{
    public function index(Request $request): View
    {
        $from = $this->date($request->query('from')) ?? today()->startOfMonth();
        $to = $this->date($request->query('to')) ?? today()->endOfMonth();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $status = in_array($request->query('status'), ['P', 'C'], true) ? $request->query('status') : null;

        $ids = null;
        if ($request->filled('q') || $request->filled('scheme')) {
            $ids = Employee::query()->filter($request->only(['q', 'scheme']))->pluck('id')->all();
        }

        // Entries that overlap the chosen range
        $entries = LeaveEntry::query()->where('start_date', '<=', $to->toDateString())->where('end_date', '>=', $from->toDateString())
            ->when($ids !== null, fn ($q) => $ids ? $q->whereIn('employee_id', $ids) : $q->whereRaw('1 = 0'))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('type'), fn ($q, $t) => $q->where('leave_type_code', $t))
            ->orderByDesc('start_date')->orderBy('employee_no')->paginate(40)->withQueryString();

        return view('leave.requests.index', [
            'entries' => $entries, 'from' => $from, 'to' => $to, 'status' => $status,
            'employees' => Employee::query()->whereIn('id', $entries->pluck('employee_id')->unique()->all())->get()->keyBy('id'),
            'types' => LeaveType::orderBy('name')->get(),
            'schemes' => Scheme::visibleTo($request->user())->orderBy('name')->get(),
        ]);
    }

    private function date(?string $v): ?Carbon
    {
        if (! $v || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            return Carbon::parse($v)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
