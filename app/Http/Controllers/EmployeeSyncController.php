<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMihcmEmployees;
use App\Models\EmployeeSyncRun;
use App\Services\Mihcm\EmployeeSyncService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class EmployeeSyncController extends Controller
{
    public function index(): View
    {
        return view('employees.sync.index', ['runs' => EmployeeSyncRun::latest('id')->paginate(15)]);
    }

    public function show(Request $request, EmployeeSyncRun $run): View
    {
        $type = $request->query('type');

        return view('employees.sync.show', [
            'run'     => $run,
            'type'    => $type,
            'types'   => $run->changes()->selectRaw('change_type, count(*) as total')->groupBy('change_type')->pluck('total', 'change_type'),
            'changes' => $run->changes()->when($type, fn ($q, $t) => $q->where('change_type', $t))->orderBy('id')->paginate(30)->withQueryString(),
        ]);
    }

    public function store(Request $request, EmployeeSyncService $service): RedirectResponse
    {
        try {
            $run = $service->start('manual', $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        SyncMihcmEmployees::dispatch($run->id);
        Audit::log('employee.sync_started', null, "MiHCM sync #{$run->id} started manually", context: ['run_id' => $run->id]);

        return redirect()->route('employees.sync.show', $run)->with('status', 'Sync queued. This page refreshes while it runs.');
    }
}
