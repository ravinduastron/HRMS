<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeContributionRate;
use App\Models\StatutoryRateVersion;
use App\Models\TaxTable;
use App\Models\TaxTableSlab;
use App\Models\TaxTableVersion;
use App\Models\User;
use App\Payroll\Decimal;
use App\Payroll\Statutory\Format;
use App\Payroll\Statutory\SlabTax;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** All statutory rules live here (a future approval engine hooks into the add/set methods). Every change adds a new immutable version. */
class StatutoryService
{
    /** @return array{0: int, 1: string} decimal places and mode used for every statutory amount (config/statutory.php) */
    public static function rounding(): array
    {
        return [(int) config('statutory.rounding_dp', 2), (string) config('statutory.rounding_mode', 'half_up')];
    }

    /* ------------------------------ lookups ------------------------------ */

    public function ratesAt(?Carbon $date = null): ?StatutoryRateVersion
    {
        return StatutoryRateVersion::where('approval_status', 'approved')
            ->whereDate('effective_from', '<=', ($date ?? today())->toDateString())->orderByDesc('effective_from')->first();
    }

    public function taxTableVersionAt(string $code, ?Carbon $date = null): ?TaxTableVersion
    {
        return TaxTableVersion::with('slabs')->whereHas('taxTable', fn ($q) => $q->where('code', $code))->where('approval_status', 'approved')
            ->whereDate('effective_from', '<=', ($date ?? today())->toDateString())->orderByDesc('effective_from')->first();
    }

    /** The employee's own rate record in force on $date (its rate may be null = the default applies). */
    public function employeeRateAt(Employee $employee, ?Carbon $date = null): ?EmployeeContributionRate
    {
        return EmployeeContributionRate::withoutGlobalScopes()->where('employee_id', $employee->id)->where('approval_status', 'approved')
            ->whereDate('effective_from', '<=', ($date ?? today())->toDateString())->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /** @return array{rate: ?string, source: string} the employee contribution rate to use; source = employee | default | missing */
    public function effectiveEmployeeRate(Employee $employee, ?Carbon $date = null): array
    {
        $own = $this->employeeRateAt($employee, $date)?->employee_rate;
        if ($own !== null) {
            return ['rate' => Decimal::plain((string) $own), 'source' => 'employee'];
        }
        $default = $this->ratesAt($date)?->rates()[$employee->provident_fund.'_employee'] ?? null;

        return ['rate' => $default, 'source' => $default === null ? 'missing' : 'default'];
    }

    /* ------------------------------ contribution rates ------------------------------ */

    /** @param array<string, string> $rates keys: StatutoryRateVersion::KEYS */
    public function addRateVersion(array $rates, Carbon $from, string $note, User $by): StatutoryRateVersion
    {
        return DB::transaction(function () use ($rates, $from, $note, $by) {
            $latest = StatutoryRateVersion::orderByDesc('effective_from')->lockForUpdate()->first();
            if ($latest && $from->copy()->startOfDay()->lte($latest->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'The effective date must be after the latest version ('.$latest->effective_from->format('d M Y').').']);
            }

            $version = new StatutoryRateVersion;
            $version->forceFill(collect(StatutoryRateVersion::KEYS)->mapWithKeys(fn ($k) => [$k.'_rate' => $rates[$k]])->all() + [
                'effective_from' => $from->toDateString(), 'change_note' => mb_substr(trim($note), 0, 500),
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();

            Audit::log('statutory.rates_added', $version, 'Contribution rates effective '.$from->format('d M Y'), new: $rates,
                context: ['version_id' => $version->id, 'effective_from' => $from->toDateString(), 'reason' => $version->change_note]);

            return $version;
        });
    }

    /* ------------------------------ tax tables ------------------------------ */

    /**
     * @param  array<int, array{up_to: ?string, rate: string, fixed: ?string}>  $rows  one per band, in order; "over" is worked out
     * @return array{version: TaxTableVersion, warnings: string[]}
     */
    public function addTaxTableVersion(TaxTable $table, array $rows, string $label, ?string $source, Carbon $from, string $note, User $by): array
    {
        $slabs = $this->buildSlabs($rows);
        $errors = SlabTax::validate($slabs);
        if ($errors) {
            throw ValidationException::withMessages(['slabs' => $errors]);
        }

        return DB::transaction(function () use ($table, $slabs, $label, $source, $from, $note, $by) {
            TaxTable::whereKey($table->id)->lockForUpdate()->firstOrFail();

            $latest = $table->versions()->first();
            if ($latest && $from->copy()->startOfDay()->lte($latest->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'The effective date must be after the latest version ('.$latest->effective_from->format('d M Y').').']);
            }

            $version = new TaxTableVersion;
            $version->forceFill([
                'tax_table_id' => $table->id, 'effective_from' => $from->toDateString(), 'label' => trim($label),
                'source_note' => $source !== null && trim($source) !== '' ? mb_substr(trim($source), 0, 255) : null,
                'change_note' => mb_substr(trim($note), 0, 500),
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();

            foreach ($slabs as $i => $s) {
                (new TaxTableSlab)->forceFill([
                    'tax_table_version_id' => $version->id, 'sort' => $i, 'over_amount' => $s['over'], 'up_to_amount' => $s['up_to'],
                    'rate' => $s['rate'], 'fixed_amount' => $s['fixed'],
                ])->save();
            }

            Audit::log('statutory.tax_table_version_added', $table, "{$table->name}: {$version->label} effective ".$from->format('d M Y'),
                new: ['label' => $version->label, 'bands' => collect($slabs)->map(fn ($s) => Format::money($s['over'], 0).' to '.($s['up_to'] === null ? 'no limit' : Format::money($s['up_to'], 0)).' at '.$s['rate'].'% less '.Format::money($s['fixed'], 0))->all()],
                context: ['version_id' => $version->id, 'effective_from' => $from->toDateString(), 'reason' => $version->change_note]);

            return ['version' => $version, 'warnings' => $this->warningsFor($table, $slabs, $from)];
        });
    }

    /** Plain rows (up_to, rate, fixed) to bands with "over" worked out from the previous band. */
    public function buildSlabs(array $rows): array
    {
        $over = '0';
        $out = [];
        foreach (array_values($rows) as $r) {
            $up = isset($r['up_to']) && trim((string) $r['up_to']) !== '' ? Decimal::make(trim((string) $r['up_to'])) : null;
            $out[] = [
                'over' => $over, 'up_to' => $up, 'rate' => Decimal::make(trim((string) $r['rate'])),
                'fixed' => isset($r['fixed']) && trim((string) $r['fixed']) !== '' ? Decimal::make(trim((string) $r['fixed'])) : '0',
            ];
            $over = $up ?? $over;
        }

        return $out;
    }

    /** Checks that never block saving: a jump between neighbouring bands, and the "annual = 12 x monthly" cross-check against the other table. */
    public function warningsFor(TaxTable $table, array $slabs, Carbon $from): array
    {
        $warnings = [];
        $tolerance = (string) config('statutory.continuity_tolerance', '1.00');
        $gap = SlabTax::maxGap($slabs);
        if (Decimal::cmp($gap, $tolerance) > 0) {
            $warnings[] = 'The tax jumps by Rs '.Format::money($gap).' between two neighbouring bands. Check the limits and fixed amounts.';
        }

        $other = TaxTable::where('kind', $table->kind === 'monthly' ? 'lump_sum' : 'monthly')->first();
        $otherVersion = $other?->versions()->with('slabs')->whereDate('effective_from', $from->toDateString())->first();
        if ($otherVersion) {
            [$monthly, $lump] = $table->kind === 'monthly' ? [$slabs, $otherVersion->toSlabs()] : [$otherVersion->toSlabs(), $slabs];
            foreach (SlabTax::annualisationProblems($monthly, $lump) as $p) {
                $warnings[] = "Cross-check with {$other->name} ({$otherVersion->label}): {$p}";
            }
        }

        return $warnings;
    }

    /* ------------------------------ employee contribution rate ------------------------------ */

    /** $rate null = remove the employee's own rate (the default of their fund applies again). */
    public function setEmployeeRate(Employee $employee, ?string $rate, Carbon $from, string $note, User $by): EmployeeContributionRate
    {
        $rate = $rate !== null && trim($rate) !== '' ? Decimal::make(trim($rate)) : null;
        if ($rate !== null && (Decimal::cmp($rate, '0') < 0 || Decimal::cmp($rate, '100') > 0)) {
            throw ValidationException::withMessages(['employee_rate' => 'The rate must be between 0 and 100.']);
        }

        return DB::transaction(function () use ($employee, $rate, $from, $note, $by) {
            Employee::withoutGlobalScopes()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $before = $this->employeeRateAt($employee, $from)?->employee_rate;
            $before = $before === null ? null : Decimal::plain((string) $before);

            $row = new EmployeeContributionRate;
            $row->forceFill([
                'employee_id' => $employee->id, 'effective_from' => $from->toDateString(), 'employee_rate' => $rate,
                'change_note' => mb_substr(trim($note), 0, 500),
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();

            Audit::log('statutory.employee_rate_set', $employee, 'Employee contribution rate '.($rate === null ? 'set back to the default' : "set to {$rate}%").' from '.$from->format('d M Y'),
                old: ['employee_rate' => $before], new: ['employee_rate' => $rate],
                context: ['effective_from' => $from->toDateString(), 'reason' => $row->change_note]);

            return $row;
        });
    }
}
