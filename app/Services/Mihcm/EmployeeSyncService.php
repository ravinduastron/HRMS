<?php

namespace App\Services\Mihcm;

use App\Models\Employee;
use App\Models\EmployeeSyncChange;
use App\Models\EmployeeSyncRun;
use App\Models\Scheme;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EmployeeSyncService
{
    public function __construct(private MihcmClient $client, private EmployeeMapper $mapper) {}

    /** Creates the run record (status "queued"). Refuses if another sync is in progress. */
    public function start(string $trigger, ?User $user = null): EmployeeSyncRun
    {
        $busy = EmployeeSyncRun::whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subHours(2))->exists();
        if ($busy) {
            throw new RuntimeException('A sync is already queued or running.');
        }

        return EmployeeSyncRun::create([
            'status' => 'queued', 'trigger' => $trigger,
            'triggered_by' => $user?->id, 'triggered_by_name' => $user?->name,
        ]);
    }

    /** Fetch everything first (no DB writes), then apply in ONE transaction: a failed sync changes nothing. */
    public function execute(EmployeeSyncRun $run): void
    {
        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            [$records] = $this->client->allEmployees();
            $stats = DB::transaction(fn () => $this->apply($run, $records));

            // The change log may live in another database, so it cannot be part of the transaction above.
            // It is written only after the employee changes have committed (never a log for a rolled-back change).
            try {
                foreach (array_chunk($stats['log'], 500) as $chunk) {
                    EmployeeSyncChange::insert($chunk);
                }
            } catch (Throwable $e) {
                report($e);
                $stats['notes'] .= "\nThe change details could not be saved to the log database: ".Str::limit($e->getMessage(), 300);
            }

            $run->update($stats['counts'] + [
                'status' => 'completed', 'finished_at' => now(), 'notes' => $stats['notes'] ?: null,
                'is_baseline' => $stats['baseline'],
            ]);
            Audit::log('employee.sync_completed', null, "MiHCM sync #{$run->id} completed", context: $stats['counts'] + ['run_id' => $run->id, 'trigger' => $run->trigger]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => Str::limit($e->getMessage(), 2000)]);
            Audit::log('employee.sync_failed', null, "MiHCM sync #{$run->id} failed: ".Str::limit($e->getMessage(), 300), context: ['run_id' => $run->id]);
            throw $e;
        }
    }

    private function apply(EmployeeSyncRun $run, array $records): array
    {
        $c = array_fill_keys(['fetched', 'created', 'updated', 'unchanged', 'deactivated', 'reactivated', 'skipped'], 0);
        $key = fn (?string $no) => mb_strtolower(trim((string) $no));
        // withoutGlobalScopes: the sync must always see EVERY employee, even if it runs inside a signed-in request
        $existing = Employee::withoutGlobalScopes()->get()->keyBy(fn ($e) => $key($e->employee_no));

        // Schemes: matched on MiHCM name; unknown ones are created (with no users allocated yet)
        $schemes = Scheme::whereNotNull('mihcm_name')->get()->keyBy(fn ($s) => mb_strtolower($s->mihcm_name));
        $schemeNames = Scheme::pluck('name')->map(fn ($n) => mb_strtolower($n))->flip();
        $newSchemes = [];
        $schemeId = function (?string $name) use (&$schemes, &$schemeNames, &$newSchemes): ?int {
            if (! $name) {
                return null;
            }
            $k = mb_strtolower($name);
            if (! $schemes->has($k)) {
                $display = $schemeNames->has($k) ? $name.' (MiHCM)' : $name; // avoid clashing with a local scheme name
                $scheme = Scheme::create(['name' => $display, 'mihcm_name' => $name, 'source' => 'mihcm']);
                $schemes->put($k, $scheme);
                $schemeNames->put(mb_strtolower($display), true);
                $newSchemes[] = $name;
            }

            return $schemes->get($k)->id;
        };
        $baseline = $existing->isEmpty();
        $now = now();
        $seen = [];
        $touched = [];
        $log = [];
        $notes = [];

        $record = function (?Employee $e, string $type, ?array $changes, array $fallback = []) use (&$log, $run, $now) {
            $log[] = [
                'employee_sync_run_id' => $run->id,
                'employee_id'          => $e?->id,
                'employee_no'          => $e?->employee_no ?? ($fallback['employee_no'] ?? null),
                'employee_name'        => $e?->full_name ?? $e?->display_name ?? ($fallback['full_name'] ?? null),
                'change_type'          => $type,
                'changes'              => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'created_at'           => $now,
            ];
        };

        foreach ($records as $api) {
            $c['fetched']++;
            $attrs = $this->mapper->map($api);

            if (! $attrs['employee_no']) {
                $c['skipped']++;
                $record(null, 'skipped', ['reason' => ['old' => null, 'new' => 'Missing employeeNumber']], $attrs);
                continue;
            }
            $k = $key($attrs['employee_no']);
            if (isset($seen[$k])) {
                $c['skipped']++;
                $record(null, 'skipped', ['reason' => ['old' => null, 'new' => 'Employee number appears twice in the API response']], $attrs);
                continue;
            }
            $seen[$k] = true;
            $attrs['scheme_id'] = $schemeId($attrs['scheme_name'] ?? null);

            $employee = $existing->get($k);

            if (! $employee) {
                $employee = (new Employee)->forceFill($attrs + ['first_synced_at' => $now, 'last_synced_at' => $now]);
                $employee->save();
                $touched[] = $employee->id;
                $c['created']++;
                $record($employee, $baseline ? 'imported' : 'created', $this->summary($employee));
                continue;
            }

            $touched[] = $employee->id;
            $employee->forceFill($attrs); // never contains is_active / is_payroll_active
            if (! $employee->isDirty()) {
                $c['unchanged']++;
                continue;
            }

            $diff = $this->diff($employee);
            $wasGone = ! $employee->getOriginal('mihcm_active') || $employee->getOriginal('mihcm_deleted');
            $isGone = ! $employee->mihcm_active || $employee->mihcm_deleted;
            $type = ! $wasGone && $isGone ? 'deactivated' : ($wasGone && ! $isGone ? 'reactivated' : 'updated');

            $employee->save();
            $c[$type === 'updated' ? 'updated' : $type]++;
            $record($employee, $type, $diff);
        }

        if ($newSchemes) {
            $notes[] = 'New scheme(s) from MiHCM: '.implode(', ', array_unique($newSchemes)).'. No users are allocated to them yet, so only users with all-scheme access can see these employees.';
        }

        // Employees the API no longer returns (only trusted after a COMPLETE fetch, and capped by a safety threshold)
        $absent = $existing->filter(fn ($e) => ! isset($seen[$key($e->employee_no)]) && $e->mihcm_active && ! $e->mihcm_deleted);
        $limit = config('mihcm.max_missing_percent');
        if ($absent->isNotEmpty() && $absent->count() > 5 && ($absent->count() / max($existing->count(), 1) * 100) > $limit) {
            $notes[] = "{$absent->count()} employees were missing from the API response (over the {$limit}% safety limit), so none were deactivated. Please check the MiHCM feed.";
        } else {
            foreach ($absent as $employee) {
                $employee->forceFill(['mihcm_active' => false])->save();
                $c['deactivated']++;
                $record($employee, 'deactivated', ['mihcm_active' => ['old' => true, 'new' => false], 'reason' => ['old' => null, 'new' => 'No longer returned by MiHCM']]);
            }
        }

        foreach (array_chunk($touched, 1000) as $ids) { // toBase(): no updated_at bump, no per-row audit noise
            Employee::withoutGlobalScopes()->whereIn('id', $ids)->toBase()->update(['last_synced_at' => $now]);
        }

        return [
            'counts' => [
                'fetched_count' => $c['fetched'], 'created_count' => $c['created'], 'updated_count' => $c['updated'],
                'unchanged_count' => $c['unchanged'], 'deactivated_count' => $c['deactivated'],
                'reactivated_count' => $c['reactivated'], 'skipped_count' => $c['skipped'],
            ],
            'notes' => implode("\n", $notes), 'baseline' => $baseline, 'log' => $log,
        ];
    }

    private function diff(Employee $e): array
    {
        $hidden = config('mihcm.hidden_fields');
        $mask = config('audit.mask');
        $plain = fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;

        return collect(array_keys($e->getDirty()))->reject(fn ($k) => $k === 'scheme_id')->mapWithKeys(fn ($k) => [
            $k => in_array($k, $hidden, true)
                ? ['old' => $mask, 'new' => $mask]
                : ['old' => $plain($e->getOriginal($k)), 'new' => $plain($e->getAttribute($k))],
        ])->all();
    }

    private function summary(Employee $e): array
    {
        return collect(['employee_no', 'full_name', 'department', 'dsg_name', 'date_joined'])->mapWithKeys(fn ($k) => [
            $k => ['old' => null, 'new' => $e->getAttribute($k) instanceof \DateTimeInterface ? $e->getAttribute($k)->format('Y-m-d') : $e->getAttribute($k)],
        ])->all();
    }
}
