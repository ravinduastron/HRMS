<?php

namespace App\Services;

use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\EmployeeCostCenter;
use App\Models\Scopes\SchemeScope;
use App\Models\User;
use App\Payroll\CostCenterTree;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All cost center rules live here. The tree is global; an employee has ONE cost center (a leaf) from an effective date, kept as immutable rows.
 * A pay run uses missing() / assertAllAssigned() to refuse employees without one, and snapshots() to store the path in the run.
 */
class CostCenterService
{
    public static function maxLevel(): int
    {
        return (int) config('costcenters.max_level', 3);
    }

    public static function sep(): string
    {
        return (string) config('costcenters.separator', ' > ');
    }

    public static function levelName(int $level): string
    {
        return (string) config("costcenters.levels.{$level}", "Level {$level}");
    }

    /** @return array<int, array> every node keyed by id (see CostCenterTree) */
    public function nodes(): array
    {
        return CostCenter::query()->get(['id', 'parent_id', 'level', 'code', 'name', 'is_active'])->mapWithKeys(fn ($c) => [$c->id => [
            'id' => (int) $c->id, 'parent_id' => $c->parent_id === null ? null : (int) $c->parent_id, 'level' => (int) $c->level,
            'code' => $c->code, 'name' => $c->name, 'is_active' => (bool) $c->is_active,
        ]])->all();
    }

    /** Active leaves as combobox options: value = id, label = full path. */
    public function leafOptions(?array $nodes = null): Collection
    {
        $nodes ??= $this->nodes();

        return collect($nodes)->filter(fn ($n) => $n['is_active'] && CostCenterTree::isLeaf($nodes, $n['id']))
            ->map(fn ($n) => ['value' => $n['id'], 'label' => CostCenterTree::label($nodes, $n['id'], self::sep())])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Employees whose LATEST assignment is each cost center (future-dated ones included), per cost center id.
     * $visibleOnly limits the count to employees the signed-in user may see; integrity checks use the global count.
     *
     * @return array<int, int>
     */
    public function currentCounts(bool $visibleOnly = false): array
    {
        $q = DB::table('employee_cost_centers as e')->selectRaw('e.cost_center_id, count(*) as total')
            ->whereRaw('e.id = (select x.id from employee_cost_centers x where x.employee_id = e.employee_id order by x.effective_from desc, x.id desc limit 1)');
        if ($visibleOnly && SchemeScope::restricts()) {
            $q->whereIn('e.employee_id', Employee::query()->select('id'));
        }

        return $q->groupBy('e.cost_center_id')->pluck('total', 'cost_center_id')->map(fn ($v) => (int) $v)->all();
    }

    /** The assignment in force on $date for each employee id (employees without one are absent). Callers pass only ids the user may see. */
    public function inForce(array $employeeIds, ?Carbon $date = null): Collection
    {
        $d = ($date ?? today())->toDateString();
        $out = collect();
        foreach (array_chunk(array_values(array_unique($employeeIds)), 1000) as $ids) {
            EmployeeCostCenter::withoutGlobalScopes()->whereIn('employee_id', $ids)->whereDate('effective_from', '<=', $d)
                ->orderByDesc('effective_from')->orderByDesc('id')->get()->unique('employee_id')
                ->each(fn ($r) => $out->put($r->employee_id, $r));
        }

        return $out;
    }

    /** Payroll-active employees (that the user may see) with no cost center in force on $date. */
    public function unassignedCount(?Carbon $date = null): int
    {
        $assigned = EmployeeCostCenter::withoutGlobalScopes()->whereDate('effective_from', '<=', ($date ?? today())->toDateString())->distinct()->pluck('employee_id');

        return Employee::query()->payrollEligible()->whereNotIn('id', $assigned)->count();
    }

    /* ------------------------------ the tree ------------------------------ */

    public function create(?int $parentId, string $code, string $name, User $by): CostCenter
    {
        $code = strtoupper(trim($code));
        $name = trim($name);

        return DB::transaction(function () use ($parentId, $code, $name) {
            $parent = $parentId ? CostCenter::whereKey($parentId)->lockForUpdate()->firstOrFail() : null;
            $level = $parent ? $parent->level + 1 : 1;

            $problem = CostCenterTree::childProblem($parent ? ['level' => (int) $parent->level, 'is_active' => (bool) $parent->is_active] : null, self::maxLevel());
            if ($problem) {
                throw ValidationException::withMessages(['parent_id' => $problem]);
            }
            if ($parent && ($this->currentCounts()[$parent->id] ?? 0) > 0) {
                throw ValidationException::withMessages(['parent_id' => "Employees are assigned to {$parent->name}. A cost center with employees cannot get sub-levels: move the employees to another cost center first."]);
            }
            $this->assertNameFree($parent?->id, $name, null);
            if (CostCenter::where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => 'This code is already used.']);
            }

            $cc = new CostCenter;
            $cc->forceFill(['parent_id' => $parent?->id, 'level' => $level, 'code' => $code, 'name' => $name, 'is_active' => true])->save(); // Auditable logs it

            return $cc;
        });
    }

    public function rename(CostCenter $cc, string $name): void
    {
        $name = trim($name);
        $this->assertNameFree($cc->parent_id, $name, $cc->id);
        $cc->update(['name' => $name]); // Auditable records old and new
    }

    public function toggle(CostCenter $cc): void
    {
        DB::transaction(function () use ($cc) {
            $cc = CostCenter::whereKey($cc->id)->lockForUpdate()->firstOrFail();

            if ($cc->is_active) {
                if ($cc->children()->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['status' => "{$cc->name} still has active sub-levels. Deactivate those first."]);
                }
                if (($this->currentCounts()[$cc->id] ?? 0) > 0) {
                    throw ValidationException::withMessages(['status' => "Employees are assigned to {$cc->name}. Move them to another cost center first."]);
                }
            } elseif ($cc->parent_id && ! CostCenter::whereKey($cc->parent_id)->value('is_active')) {
                throw ValidationException::withMessages(['status' => 'The parent cost center is inactive. Activate it first.']);
            }
            $cc->update(['is_active' => ! $cc->is_active]);
        });
    }

    private function assertNameFree(?int $parentId, string $name, ?int $ignoreId): void
    {
        $exists = CostCenter::where('parent_id', $parentId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'This level already has a cost center with that name.']);
        }
    }

    /* ------------------------------ assigning ------------------------------ */

    private function assertAssignable(CostCenter $cc): void
    {
        if (! $cc->is_active) {
            throw ValidationException::withMessages(['cost_center_id' => 'This cost center is inactive.']);
        }
        if ($cc->children()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['cost_center_id' => "{$cc->name} has sub-levels. Choose the lowest level (for example Finance > Accounting)."]);
        }
    }

    public function assign(Employee $employee, CostCenter $cc, Carbon $date, string $note, User $by): EmployeeCostCenter
    {
        $r = $this->assignMany([$employee->id], $cc, $date, $note, $by, single: true);

        return $r['rows'][0] ?? throw ValidationException::withMessages(['cost_center_id' => 'The employee is already on that cost center on that date.']);
    }

    /**
     * Gives the employees ONE new assignment each. Employees already on that cost center on that date are left alone (counted as unchanged),
     * inactive employees are skipped. All or nothing. One audit entry for the batch (one per employee when $single).
     *
     * @return array{assigned: int, unchanged: int, skipped: int, rows: EmployeeCostCenter[]}
     */
    public function assignMany(array $employeeIds, CostCenter $cc, Carbon $date, string $note, User $by, bool $single = false): array
    {
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['change_note' => 'Give a reason for the change.']);
        }
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        if (! $ids) {
            throw ValidationException::withMessages(['employee_ids' => 'Choose at least one employee.']);
        }
        $date = $date->copy()->startOfDay();

        return DB::transaction(function () use ($ids, $cc, $date, $note, $by, $single) {
            $cc = CostCenter::whereKey($cc->id)->lockForUpdate()->firstOrFail();
            $this->assertAssignable($cc);
            $label = CostCenterTree::label($this->nodes(), $cc->id, self::sep());

            $employees = Employee::query()->whereIn('id', $ids)->get(); // the scheme scope applies
            if ($employees->count() !== count($ids)) {
                throw ValidationException::withMessages(['employee_ids' => 'An employee was not found, or is not in your schemes.']);
            }
            Employee::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');

            $now = $this->inForce($ids, $date);
            $assigned = $unchanged = $skipped = 0;
            $rows = [];
            foreach ($employees as $e) {
                if (! $e->is_active) {
                    $skipped++;
                    continue;
                }
                if (($now[$e->id] ?? null)?->cost_center_id === $cc->id) {
                    $unchanged++;
                    continue;
                }
                $row = new EmployeeCostCenter;
                $row->forceFill([
                    'employee_id' => $e->id, 'cost_center_id' => $cc->id, 'effective_from' => $date->toDateString(), 'path_label' => mb_substr($label, 0, 255),
                    'change_note' => mb_substr($note, 0, 500), 'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                    'created_by' => $by->id, 'created_by_name' => $by->name,
                ])->save();
                $rows[] = $row;
                $assigned++;

                if ($single) {
                    Audit::log('employee.cost_center_assigned', $e, "Cost center {$label} from ".$date->format('d M Y'),
                        old: ['cost_center' => ($now[$e->id] ?? null)?->path_label], new: ['cost_center' => $label], context: ['effective_from' => $date->toDateString(), 'reason' => $note]);
                }
            }
            if (! $single && $assigned > 0) {
                Audit::log('cost_center.bulk_assigned', $cc, "{$assigned} employee(s) assigned to {$label} from ".$date->format('d M Y'),
                    context: ['assigned' => $assigned, 'unchanged' => $unchanged, 'skipped_inactive' => $skipped, 'effective_from' => $date->toDateString(), 'reason' => $note, 'employee_ids' => array_slice(array_map(fn ($r) => $r->employee_id, $rows), 0, 200)]);
            }

            return ['assigned' => $assigned, 'unchanged' => $unchanged, 'skipped' => $skipped, 'rows' => $rows];
        });
    }

    /* ------------------------------ for the pay run ------------------------------ */

    /** Employees (of the given ids) with no cost center in force on $date. Use the PERIOD END date, like the salary lookup. */
    public function missing(array $employeeIds, Carbon $date): Collection
    {
        $have = $this->inForce($employeeIds, $date);

        return Employee::withoutGlobalScopes()->whereIn('id', $employeeIds)->orderBy('employee_no')->get()->reject(fn ($e) => $have->has($e->id))->values();
    }

    /** employee id => snapshot (CostCenterTree::snapshot) or null when none is in force. The run stores this, so later changes never rewrite it. */
    public function snapshots(array $employeeIds, Carbon $date): array
    {
        $nodes = $this->nodes();
        $have = $this->inForce($employeeIds, $date);
        $out = [];
        foreach ($employeeIds as $id) {
            $row = $have->get($id);
            $out[$id] = $row ? CostCenterTree::snapshot($nodes, (int) $row->cost_center_id, self::maxLevel(), self::sep()) : null;
        }

        return $out;
    }

    /** Pay run rule: refuses to go on while any employee has no cost center. Returns the snapshots to store. */
    public function assertAllAssigned(array $employeeIds, Carbon $date): array
    {
        $missing = $this->missing($employeeIds, $date);
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['cost_center' => $missing->count().' employee(s) have no cost center on '.$date->format('d M Y').' ('
                .$missing->take(10)->pluck('employee_no')->implode(', ').($missing->count() > 10 ? ', ...' : '').'). Assign one under Payroll > Cost centers.']);
        }

        return $this->snapshots($employeeIds, $date);
    }
}
