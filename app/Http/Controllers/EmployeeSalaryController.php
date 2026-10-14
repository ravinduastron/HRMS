<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayComponent;
use App\Models\SalaryStructure;
use App\Services\EmployeeSalaryService;
use App\Support\Audit;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Employee > Salary tab actions. Every action opens a new immutable salary version. */
class EmployeeSalaryController extends Controller
{
    private const AMOUNT = ['regex:/^\d{1,12}(\.\d{1,2})?$/'];

    public function __construct(private EmployeeSalaryService $salary) {}

    public function assign(Request $request, Employee $employee): RedirectResponse
    {
        return $this->run($employee, function () use ($request, $employee) {
            $data = $request->validate($this->common() + ['structure_id' => ['required', 'integer']]);
            $structure = SalaryStructure::find($data['structure_id']); // scheme scope applies
            if (! $structure) {
                throw ValidationException::withMessages(['structure_id' => 'That structure was not found.']);
            }
            $date = Carbon::parse($data['effective_from']);
            $this->salary->assign($employee, $structure, $date, $data['change_note'], $request->user());

            return $this->done($employee, $date, "Structure {$structure->name} assigned.");
        });
    }

    public function addComponent(Request $request, Employee $employee): RedirectResponse
    {
        return $this->run($employee, function () use ($request, $employee) {
            $data = $request->validate($this->common() + [
                'pay_component_id' => ['required', 'integer', 'exists:pay_components,id'],
                'amount' => ['nullable', ...self::AMOUNT],
            ]);
            $component = PayComponent::findOrFail($data['pay_component_id']);
            $date = Carbon::parse($data['effective_from']);
            $this->salary->addComponent($employee, $component, $data['amount'] ?? null, $date, $data['change_note'], $request->user());

            return $this->done($employee, $date, "{$component->code} added.");
        });
    }

    public function updateAmount(Request $request, Employee $employee, PayComponent $component): RedirectResponse
    {
        return $this->run($employee, function () use ($request, $employee, $component) {
            $data = $request->validate($this->common() + ['amount' => ['required', ...self::AMOUNT]]);
            $date = Carbon::parse($data['effective_from']);
            $this->salary->setAmount($employee, $component, $data['amount'], $date, $data['change_note'], $request->user());

            return $this->done($employee, $date, "Amount for {$component->code} changed.");
        });
    }

    public function removeComponent(Request $request, Employee $employee, PayComponent $component): RedirectResponse
    {
        return $this->run($employee, function () use ($request, $employee, $component) {
            $data = $request->validate($this->common());
            $date = Carbon::parse($data['effective_from']);
            $this->salary->removeComponent($employee, $component, $date, $data['change_note'], $request->user());

            return $this->done($employee, $date, "{$component->code} removed.");
        });
    }

    /** All amounts of the version in force, on demand, always logged (one audit entry per click). */
    public function reveal(Employee $employee): JsonResponse
    {
        $version = $this->salary->inForce($employee);
        abort_unless($version, 404);

        Audit::log('employee_salary.revealed', $employee, 'Salary amounts viewed', context: ['version_id' => $version->id]);

        return response()->json([
            'amounts' => $version->lines->filter(fn ($l) => $l->amount !== null)->mapWithKeys(fn ($l) => [$l->id => $l->amount]),
            'seconds' => (int) config('salary.reveal_seconds'),
        ])->header('Cache-Control', 'no-store');
    }

    /* ------------------------------------------------------------------ */

    private function common(): array
    {
        return ['effective_from' => ['required', 'date'], 'change_note' => ['required', 'string', 'max:500']];
    }

    /** The forms live in popups, so a problem is reported as a message on the page rather than as field errors. */
    private function run(Employee $employee, Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            return $this->back($employee)->with('error', (string) collect($e->errors())->flatten()->first());
        }
    }

    private function done(Employee $employee, Carbon $date, string $message): RedirectResponse
    {
        $redirect = $this->back($employee);

        return $this->salary->hasLaterVersion($employee, $date)
            ? $redirect->with('warning', $message.' A later salary change already exists and does not include this change.')
            : $redirect->with('status', $message);
    }

    private function back(Employee $employee): RedirectResponse
    {
        return redirect()->route('employees.show', [$employee, 'tab' => 'salary']);
    }
}
