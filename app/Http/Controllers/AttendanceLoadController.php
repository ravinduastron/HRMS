<?php

namespace App\Http\Controllers;

use App\Jobs\LoadAttendanceData;
use App\Models\AttendanceLoadChange;
use App\Models\AttendanceLoadRun;
use App\Services\AttendanceLoadService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Loading touches every employee, so (like the MiHCM employee sync) the routes also require schemes.all. */
class AttendanceLoadController extends Controller
{
    public function index(): View
    {
        return view('attendance.load.index', [
            'runs' => AttendanceLoadRun::latest('id')->paginate(15),
            'defaultFrom' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'defaultTo' => today()->subMonthNoOverflow()->endOfMonth()->toDateString(),
        ]);
    }

    public function store(Request $request, AttendanceLoadService $service): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'scope' => ['required', 'in:attendance,leave,both'],
        ]);

        try {
            $run = $service->start($data['scope'], Carbon::parse($data['from']), Carbon::parse($data['to']), 'manual', $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        LoadAttendanceData::dispatch($run->id);
        Audit::log('attendance.load_started', null, "Attendance/leave load #{$run->id} started manually ({$run->scope}, {$data['from']} to {$data['to']})", context: ['run_id' => $run->id]);

        return redirect()->route('attendance.load.show', $run)->with('status', 'Load queued. This page refreshes while it runs.');
    }

    public function show(Request $request, AttendanceLoadRun $run): View
    {
        $kind = in_array($request->query('kind'), ['attendance', 'leave'], true) ? $request->query('kind') : null;
        $type = in_array($request->query('type'), ['updated', 'missing', 'restored'], true) ? $request->query('type') : null;

        return view('attendance.load.show', [
            'run' => $run, 'kind' => $kind, 'type' => $type,
            'changes' => AttendanceLoadChange::where('load_run_id', $run->id)
                ->when($kind, fn ($q, $k) => $q->where('kind', $k))->when($type, fn ($q, $t) => $q->where('change_type', $t))
                ->orderBy('id')->paginate(30)->withQueryString(),
        ]);
    }
}
