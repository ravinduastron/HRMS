<?php

namespace App\Services;

use App\Models\AttendanceLoadChange;
use App\Models\AttendanceLoadRun;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveEntry;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Mihcm\AttendanceMapper;
use App\Services\Mihcm\LeaveMapper;
use App\Services\Mihcm\MihcmClient;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Loads attendance and leave from MiHCM for a date range, on demand (never scheduled).
 * A load NEVER makes data final: MiHCM does not say what is final. A pay run snapshots a summary in the main database.
 *
 * Same rules as the employee sync: fetch EVERYTHING first (no database writes), then apply in ONE transaction, so a failed
 * fetch changes nothing. Rows MiHCM stops returning are flagged and kept, never deleted.
 */
class AttendanceLoadService
{
    public function __construct(private MihcmClient $client) {}

    /** Creates the run record (status "queued"). Refuses a bad range or when another load is in progress. */
    public function start(string $scope, Carbon $from, Carbon $to, string $trigger, ?User $user = null): AttendanceLoadRun
    {
        if (! in_array($scope, ['attendance', 'leave', 'both'], true)) {
            throw new InvalidArgumentException('Scope must be attendance, leave or both.');
        }
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        if ($to->lt($from)) {
            throw new RuntimeException('The end date must not be before the start date.');
        }
        if ($to->gt(today())) {
            throw new RuntimeException('The end date cannot be in the future.');
        }
        $max = (int) config('attendance.max_days');
        if ((int) $from->diffInDays($to, true) + 1 > $max) {
            throw new RuntimeException("Load at most {$max} days at a time.");
        }
        if (AttendanceLoadRun::whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subHours(2))->exists()) {
            throw new RuntimeException('A load is already queued or running.');
        }

        return AttendanceLoadRun::create([
            'status' => 'queued', 'trigger' => $trigger, 'scope' => $scope,
            'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
            'triggered_by' => $user?->id, 'triggered_by_name' => $user?->name,
        ]);
    }

    public function execute(AttendanceLoadRun $run): void
    {
        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $from = $run->from_date->copy();
            $to = $run->to_date->copy();
            $doAttendance = in_array($run->scope, ['attendance', 'both'], true);
            $doLeave = in_array($run->scope, ['leave', 'both'], true);

            // 1. Fetch everything first. Nothing is written until every call has succeeded.
            $attendanceRows = $doAttendance ? $this->client->allAttendance($from->toDateString(), $to->toDateString())[0] : [];
            $months = $this->months($from, $to);
            $leaveRows = [];
            if ($doLeave) {
                foreach ($months as [$y, $m]) {
                    array_push($leaveRows, ...$this->client->leaveForMonth($y, $m));
                }
            }

            // 2. Employees come from the main database (no cross-database queries): number => id
            $employees = Employee::withoutGlobalScopes()->get(['id', 'employee_no'])
                ->mapWithKeys(fn ($e) => [$this->key($e->employee_no) => $e->id])->all();

            // 3. Apply everything in ONE transaction on the attendance connection
            $now = now();
            $out = DB::connection(config('attendance.connection'))->transaction(function () use ($run, $doAttendance, $doLeave, $attendanceRows, $leaveRows, $months, $employees, $from, $to, $now) {
                $result = ['counts' => [], 'notes' => [], 'unmatched' => ['attendance' => [], 'leave' => []], 'changes' => []];

                if ($doAttendance) {
                    $a = $this->applyAttendance($run, $attendanceRows, $employees, $from->toDateString(), $to->toDateString(), $now);
                    $result['counts']['attendance'] = $a['counts'];
                    $result['notes'] = array_merge($result['notes'], $a['notes']);
                    $result['unmatched']['attendance'] = $a['unmatched'];
                    $result['changes'] = array_merge($result['changes'], $a['changes']);
                }
                if ($doLeave) {
                    $l = $this->applyLeave($run, $leaveRows, $months, $employees, $now);
                    $result['counts']['leave'] = $l['counts'];
                    $result['notes'] = array_merge($result['notes'], $l['notes']);
                    $result['unmatched']['leave'] = $l['unmatched'];
                    $result['changes'] = array_merge($result['changes'], $l['changes']);
                }

                foreach (array_chunk($result['changes'], 500) as $chunk) {
                    AttendanceLoadChange::insert($chunk);
                }

                return $result;
            });

            $quality = $this->quality($run, $from->toDateString(), $to->toDateString(), $out);

            $run->update([
                'status' => 'completed', 'finished_at' => now(), 'counts' => $out['counts'], 'quality' => $quality,
                'notes' => $out['notes'] ? implode("\n", $out['notes']) : null,
            ]);
            Audit::log('attendance.load_completed', null, "Attendance/leave load #{$run->id} completed ({$run->scope}, {$from->toDateString()} to {$to->toDateString()})",
                context: ['run_id' => $run->id, 'trigger' => $run->trigger, 'counts' => $out['counts']]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => Str::limit($e->getMessage(), 2000)]);
            Audit::log('attendance.load_failed', null, "Attendance/leave load #{$run->id} failed: ".Str::limit($e->getMessage(), 300), context: ['run_id' => $run->id]);
            throw $e;
        }
    }

    /* ------------------------------ attendance ------------------------------ */

    private function applyAttendance(AttendanceLoadRun $run, array $rows, array $employees, string $from, string $to, Carbon $now): array
    {
        $mapper = new AttendanceMapper((string) config('attendance.employee_no_field', 'number'), config('attendance.employee_no_pad') ? (int) config('attendance.employee_no_pad') : null);
        $c = $this->zeroCounts();
        $unmatched = [];
        $changes = [];
        $notes = [];
        $seen = [];
        $inserts = [];

        $existing = AttendanceRecord::withoutGlobalScopes()->whereBetween('work_date', [$from, $to])->get()
            ->keyBy(fn ($r) => $r->employee_id.'|'.$r->work_date->toDateString());

        foreach ($rows as $api) {
            $c['fetched']++;
            $m = is_array($api) ? $mapper->map($api) : null;
            if ($m === null || $m['work_date'] < $from || $m['work_date'] > $to) { // unusable, or outside the range asked for
                $c['skipped']++;
                continue;
            }
            $employeeId = $employees[$this->key($m['employee_no'])] ?? null;
            if (! $employeeId) {
                $c['unmatched']++;
                $unmatched[$m['employee_no']] = true;
                continue;
            }
            $k = $employeeId.'|'.$m['work_date'];
            if (isset($seen[$k])) { // the same employee and day twice in the feed: the first one wins
                $c['skipped']++;
                continue;
            }
            $seen[$k] = true;
            $hash = $mapper->hash($m);
            $row = $existing->get($k);

            if (! $row) {
                $inserts[] = $m + [
                    'employee_id' => $employeeId, 'row_hash' => $hash, 'load_run_id' => $run->id,
                    'first_loaded_at' => $now, 'loaded_at' => $now, 'missing_since' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
                $c['created']++;
                continue;
            }

            $wasMissing = $row->missing_since !== null;
            if ($row->row_hash === $hash) {
                if ($wasMissing) { // MiHCM returns it again, unchanged
                    $this->touch(AttendanceRecord::class, $row->id, $run, $now);
                    $c['restored']++;
                    $changes[] = $this->change($run, $employeeId, $m['employee_no'], 'attendance', $m['work_date'], 'restored', null, $now);
                } else {
                    $c['unchanged']++;
                }
                continue;
            }

            $diff = $this->diff($row, $m, AttendanceMapper::COLUMNS);
            AttendanceRecord::withoutGlobalScopes()->whereKey($row->id)->toBase()->update(
                $m + ['row_hash' => $hash, 'load_run_id' => $run->id, 'loaded_at' => $now, 'missing_since' => null, 'updated_at' => $now]
            );
            $c['updated']++;
            $changes[] = $this->change($run, $employeeId, $m['employee_no'], 'attendance', $m['work_date'], 'updated', $diff, $now);
            if ($wasMissing) {
                $c['restored']++;
            }
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            AttendanceRecord::insert($chunk);
        }

        // Rows MiHCM no longer returns for this range: flag and keep (never delete), unless that looks like a broken feed
        $absent = $existing->filter(fn ($r, $k) => ! isset($seen[$k]) && $r->missing_since === null);
        $limit = (float) config('attendance.max_missing_percent');
        if ($absent->isNotEmpty() && $absent->count() > 5 && ($absent->count() / max($existing->count(), 1) * 100) > $limit) {
            $notes[] = "{$absent->count()} stored attendance rows were missing from the MiHCM response (over the {$limit}% safety limit), so none were flagged. Please check the MiHCM feed.";
        } else {
            foreach ($absent as $row) {
                AttendanceRecord::withoutGlobalScopes()->whereKey($row->id)->toBase()->update(['missing_since' => $now, 'updated_at' => $now]);
                $c['missing']++;
                $changes[] = $this->change($run, $row->employee_id, $row->employee_no, 'attendance', $row->work_date->toDateString(), 'missing', null, $now);
            }
        }

        if ($unmatched) {
            $notes[] = 'Attendance: '.count($unmatched).' employee number(s) matched no employee here, so their rows were not stored (first: '.implode(', ', array_slice(array_keys($unmatched), 0, 10)).').';
        }

        return ['counts' => $c, 'notes' => $notes, 'unmatched' => array_keys($unmatched), 'changes' => $changes];
    }

    /* ------------------------------ leave ------------------------------ */

    private function applyLeave(AttendanceLoadRun $run, array $rows, array $months, array $employees, Carbon $now): array
    {
        $mapper = new LeaveMapper;
        $c = $this->zeroCounts();
        $seen = [];
        $mapped = [];
        $types = [];
        $unmatched = [];
        $changes = [];
        $notes = [];

        foreach ($rows as $api) {
            $c['fetched']++;
            $m = is_array($api) ? $mapper->map($api) : null;
            if ($m === null || isset($seen[$m['leave_entry_code']])) {
                $c['skipped']++;
                continue;
            }
            $seen[$m['leave_entry_code']] = true;
            $employeeId = $employees[$this->key($m['employee_no'])] ?? null;
            if (! $employeeId) {
                $c['unmatched']++;
                $unmatched[$m['employee_no']] = true;
                continue;
            }
            $mapped[] = [$employeeId, $m];
            $types[$m['leave_type_code']] = $m['leave_type_name'];
        }

        // New leave types appear as "needs review" until an administrator marks them paid / no-pay
        $newTypes = [];
        foreach ($types as $code => $name) {
            $type = LeaveType::where('code', $code)->first();
            if (! $type) {
                LeaveType::create(['code' => $code, 'name' => $name, 'is_no_pay' => null, 'first_seen_at' => $now]);
                $newTypes[] = "{$name} ({$code})";
            } elseif ($type->name !== $name) {
                $type->update(['name' => $name]);
            }
        }
        if ($newTypes) {
            $notes[] = 'New leave type(s) need review (paid or no-pay): '.implode(', ', $newTypes).'.';
        }

        $existing = LeaveEntry::withoutGlobalScopes()->whereIn('leave_entry_code', array_keys($seen))->get()->keyBy('leave_entry_code');
        $inserts = [];

        foreach ($mapped as [$employeeId, $m]) {
            $hash = $mapper->hash($m);
            $row = $existing->get($m['leave_entry_code']);

            if (! $row) {
                $inserts[] = $m + [
                    'employee_id' => $employeeId, 'row_hash' => $hash, 'load_run_id' => $run->id,
                    'first_loaded_at' => $now, 'loaded_at' => $now, 'missing_since' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
                $c['created']++;
                continue;
            }

            $wasMissing = $row->missing_since !== null;
            if ($row->row_hash === $hash) {
                if ($wasMissing) {
                    $this->touch(LeaveEntry::class, $row->id, $run, $now);
                    $c['restored']++;
                    $changes[] = $this->change($run, $employeeId, $m['employee_no'], 'leave', (string) $m['leave_entry_code'], 'restored', null, $now);
                } else {
                    $c['unchanged']++;
                }
                continue;
            }

            $diff = $this->diff($row, $m, LeaveMapper::COLUMNS);
            LeaveEntry::withoutGlobalScopes()->whereKey($row->id)->toBase()->update(
                $m + ['employee_id' => $employeeId, 'row_hash' => $hash, 'load_run_id' => $run->id, 'loaded_at' => $now, 'missing_since' => null, 'updated_at' => $now]
            );
            $c['updated']++;
            $changes[] = $this->change($run, $employeeId, $m['employee_no'], 'leave', (string) $m['leave_entry_code'], 'updated', $diff, $now);
            if ($wasMissing) {
                $c['restored']++;
            }
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            LeaveEntry::insert($chunk);
        }

        // Cancelled leave disappears from the feed. Only entries that START in a month we fetched can be judged missing
        // (how MiHCM lists leave spanning two months is not documented, so those are never flagged).
        $first = Carbon::create($months[0][0], $months[0][1], 1)->toDateString();
        $lastMonth = end($months);
        $last = Carbon::create($lastMonth[0], $lastMonth[1], 1)->endOfMonth()->toDateString();
        $absent = LeaveEntry::withoutGlobalScopes()->whereBetween('start_date', [$first, $last])->whereNull('missing_since')->get()
            ->filter(fn ($r) => ! isset($seen[$r->leave_entry_code]));
        $limit = (float) config('attendance.max_missing_percent');
        $inRange = LeaveEntry::withoutGlobalScopes()->whereBetween('start_date', [$first, $last])->count();

        if ($absent->isNotEmpty() && $absent->count() > 5 && ($absent->count() / max($inRange, 1) * 100) > $limit) {
            $notes[] = "{$absent->count()} stored leave entries were missing from the MiHCM response (over the {$limit}% safety limit), so none were flagged. Please check the MiHCM feed.";
        } else {
            foreach ($absent as $row) {
                LeaveEntry::withoutGlobalScopes()->whereKey($row->id)->toBase()->update(['missing_since' => $now, 'updated_at' => $now]);
                $c['missing']++;
                $changes[] = $this->change($run, $row->employee_id, $row->employee_no, 'leave', (string) $row->leave_entry_code, 'missing', null, $now);
            }
        }

        if ($unmatched) {
            $notes[] = 'Leave: '.count($unmatched).' employee number(s) matched no employee here, so their entries were not stored (first: '.implode(', ', array_slice(array_keys($unmatched), 0, 10)).').';
        }

        return ['counts' => $c, 'notes' => $notes, 'unmatched' => array_keys($unmatched), 'changes' => $changes];
    }

    /* ------------------------------ data-quality report ------------------------------ */

    /** What a person should look at before payroll uses this data. Each item: key, label, count, severity (ok|warning), sample[] */
    private function quality(AttendanceLoadRun $run, string $from, string $to, array $out): array
    {
        $items = [];
        $add = function (string $key, string $label, int $count, array $sample = []) use (&$items) {
            $items[] = ['key' => $key, 'label' => $label, 'count' => $count, 'severity' => $count > 0 ? 'warning' : 'ok', 'sample' => array_slice($sample, 0, 10)];
        };

        if (in_array($run->scope, ['attendance', 'both'], true)) {
            $unapproved = AttendanceRecord::withoutGlobalScopes()->whereBetween('work_date', [$from, $to])->whereNull('missing_since')->where('approval_done', false)
                ->where(fn ($q) => $q->where('ot_minutes', '>', 0)->orWhere('double_ot_minutes', '>', 0)->orWhere('triple_ot_minutes', '>', 0));
            $add('unapproved_ot', 'Days with overtime that is not approved yet', (clone $unapproved)->count(),
                (clone $unapproved)->orderBy('work_date')->limit(10)->get(['employee_no', 'work_date'])->map(fn ($r) => $r->employee_no.' '.$r->work_date->format('d M'))->all());

            $withRows = AttendanceRecord::withoutGlobalScopes()->whereBetween('work_date', [$from, $to])->distinct()->pluck('employee_id')->all();
            $noData = Employee::withoutGlobalScopes()->payrollEligible()
                ->where(fn ($q) => $q->whereNull('date_joined')->orWhere('date_joined', '<=', $to))
                ->where(fn ($q) => $q->whereNull('last_working_date')->orWhere('last_working_date', '>=', $from))
                ->whereNotIn('id', $withRows);
            $add('no_data', 'Employees in payroll with no attendance in this range', (clone $noData)->count(), (clone $noData)->orderBy('employee_no')->limit(10)->pluck('employee_no')->all());

            $add('unmatched_attendance', 'Attendance employee numbers that match no employee here', count($out['unmatched']['attendance'] ?? []), $out['unmatched']['attendance'] ?? []);
            $add('missing_attendance', 'Attendance rows MiHCM stopped returning (kept, flagged)', (int) ($out['counts']['attendance']['missing'] ?? 0));
            $add('changed_attendance', 'Attendance rows that changed since the previous load', (int) ($out['counts']['attendance']['updated'] ?? 0));
        }

        if (in_array($run->scope, ['leave', 'both'], true)) {
            $overlap = LeaveEntry::withoutGlobalScopes()->where('start_date', '<=', $to)->where('end_date', '>=', $from)->whereNull('missing_since');
            $pending = (clone $overlap)->where('status', 'P');
            $add('pending_leave', 'Pending leave (not deducted until confirmed)', (clone $pending)->count(),
                (clone $pending)->orderBy('start_date')->limit(10)->get(['employee_no', 'start_date'])->map(fn ($r) => $r->employee_no.' '.$r->start_date->format('d M'))->all());

            $codes = (clone $overlap)->distinct()->pluck('leave_type_code')->all();
            $review = LeaveType::whereIn('code', $codes)->whereNull('is_no_pay')->orderBy('name')->get();
            $add('leave_types_review', 'Leave types not yet marked paid or no-pay', $review->count(), $review->map(fn ($t) => "{$t->name} ({$t->code})")->all());

            $add('unmatched_leave', 'Leave employee numbers that match no employee here', count($out['unmatched']['leave'] ?? []), $out['unmatched']['leave'] ?? []);
            $add('missing_leave', 'Leave entries MiHCM stopped returning (treated as cancelled, kept, flagged)', (int) ($out['counts']['leave']['missing'] ?? 0));
            $add('changed_leave', 'Leave entries that changed since the previous load', (int) ($out['counts']['leave']['updated'] ?? 0));
        }

        return $items;
    }

    /* ------------------------------ helpers ------------------------------ */

    private function zeroCounts(): array
    {
        return array_fill_keys(['fetched', 'created', 'updated', 'unchanged', 'restored', 'missing', 'unmatched', 'skipped'], 0);
    }

    /** [[year, month], ...] for every calendar month the range touches */
    private function months(Carbon $from, Carbon $to): array
    {
        $out = [];
        $cursor = $from->copy()->startOfMonth();
        while ($cursor->lte($to)) {
            $out[] = [$cursor->year, $cursor->month];
            $cursor->addMonth();
        }

        return $out;
    }

    private function key(?string $employeeNo): string
    {
        return mb_strtolower(trim((string) $employeeNo));
    }

    /** Clears the "missing" flag and records which load saw the row again. */
    private function touch(string $model, int $id, AttendanceLoadRun $run, Carbon $now): void
    {
        $model::withoutGlobalScopes()->whereKey($id)->toBase()->update(['missing_since' => null, 'load_run_id' => $run->id, 'loaded_at' => $now, 'updated_at' => $now]);
    }

    private function change(AttendanceLoadRun $run, int $employeeId, string $employeeNo, string $kind, string $ref, string $type, ?array $changes, Carbon $now): array
    {
        return [
            'load_run_id' => $run->id, 'employee_id' => $employeeId, 'employee_no' => $employeeNo, 'kind' => $kind, 'ref' => $ref,
            'change_type' => $type, 'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null, 'created_at' => $now,
        ];
    }

    /** {column: {old, new}} for the columns whose value really changed (values compared in their stored text form). */
    private function diff($row, array $new, array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            $old = $this->norm($row->getRawOriginal($col));
            $now = $this->norm($new[$col] ?? null);
            if ($old !== $now) {
                $out[$col] = ['old' => $old, 'new' => $now];
            }
        }

        return $out;
    }

    private function norm(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $s = (string) $v;
        if (preg_match('/^-?\d+\.\d+$/', $s)) {
            $s = rtrim(rtrim($s, '0'), '.'); // "2.00" and "2" are the same amount
        }

        return $s;
    }
}
