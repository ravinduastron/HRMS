<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Scheme;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/** Daily attendance as loaded from MiHCM (read-only). Scheme restrictions apply automatically. */
class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $from = $this->date($request->query('from')) ?? today()->startOfMonth();
        $to = $this->date($request->query('to')) ?? today();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $status = in_array($request->query('status'), ['absent', 'late', 'ot', 'unapproved_ot', 'missing'], true) ? $request->query('status') : null;

        // Employees live in the main database: resolve ids first (no cross-database joins)
        $ids = null;
        if ($request->filled('q') || $request->filled('scheme')) {
            $ids = Employee::query()->filter($request->only(['q', 'scheme']))->pluck('id')->all();
        }

        $records = AttendanceRecord::query()->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->when($ids !== null, fn ($q) => $ids ? $q->whereIn('employee_id', $ids) : $q->whereRaw('1 = 0'))
            ->when($status === 'absent', fn ($q) => $q->where('expected', true)->where('present', false))
            ->when($status === 'late', fn ($q) => $q->where('late_minutes', '>', 0))
            ->when($status === 'ot', fn ($q) => $q->where(fn ($w) => $w->where('ot_minutes', '>', 0)->orWhere('double_ot_minutes', '>', 0)->orWhere('triple_ot_minutes', '>', 0)))
            ->when($status === 'unapproved_ot', fn ($q) => $q->where('approval_done', false)->where(fn ($w) => $w->where('ot_minutes', '>', 0)->orWhere('double_ot_minutes', '>', 0)->orWhere('triple_ot_minutes', '>', 0)))
            ->when($status === 'missing', fn ($q) => $q->whereNotNull('missing_since'))
            ->orderByDesc('work_date')->orderBy('employee_no')->paginate(40)->withQueryString();

        return view('attendance.timesheets.index', [
            'records' => $records, 'from' => $from, 'to' => $to, 'status' => $status,
            'employees' => Employee::query()->whereIn('id', $records->pluck('employee_id')->unique()->all())->get()->keyBy('id'),
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
