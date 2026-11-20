<?php

namespace App\Http\Controllers;

use App\Models\CostCenter;
use App\Payroll\CostCenterTree;
use App\Services\CostCenterService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The cost center tree. Viewing needs cost-centers.view; changing also needs schemes.all (the tree is global). */
class CostCenterController extends Controller
{
    public function __construct(private CostCenterService $service) {}

    public function index(): View
    {
        $nodes = $this->service->nodes();

        return view('payroll.cost-centers.index', [
            'flat' => CostCenterTree::flatten($nodes), 'nodes' => $nodes,
            'counts' => $this->service->currentCounts(true), 'unassigned' => $this->service->unassignedCount(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,29}$/', 'unique:cost_centers,code'],
            'name' => ['required', 'string', 'max:100'],
        ], ['code.regex' => 'Use letters, digits, dash and underscore (up to 30).']);

        $cc = $this->service->create($d['parent_id'] ?? null, $d['code'], $d['name'], $request->user());

        return back()->with('status', "Cost center {$cc->code} added.");
    }

    public function update(Request $request, CostCenter $costCenter): RedirectResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:100']]);

        return $this->attempt(function () use ($costCenter, $d) {
            $this->service->rename($costCenter, $d['name']);

            return 'Cost center renamed. Finished pay runs keep the names they were calculated with.';
        });
    }

    public function toggle(CostCenter $costCenter): RedirectResponse
    {
        return $this->attempt(function () use ($costCenter) {
            $this->service->toggle($costCenter);

            return $costCenter->fresh()->is_active ? 'Cost center activated.' : 'Cost center deactivated.';
        });
    }

    private function attempt(Closure $action): RedirectResponse
    {
        try {
            return back()->with('status', $action());
        } catch (ValidationException $e) {
            return back()->with('error', (string) collect($e->errors())->flatten()->first());
        }
    }
}
