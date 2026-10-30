<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanMarketRate;
use App\Models\LoanValuation;
use App\Models\LoanValuationLine;
use App\Models\User;
use App\Payroll\Decimal;
use App\Payroll\Loans\LoanFairValue;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fair value of the loan book: what is still to be collected (principal and interest), discounted at the market rate.
 * "Fair value - outstanding principal" is the difference to report (negative when loans are cheaper than the market, as interest-free staff loans are).
 * A month-end run is saved as a snapshot, so it stays exactly as it was calculated.
 */
class LoanValuationService
{
    public function __construct(private LoanService $loans) {}

    public function marketRateAt(?Carbon $date = null): ?LoanMarketRate
    {
        return LoanMarketRate::where('approval_status', 'approved')->whereDate('effective_from', '<=', ($date ?? today())->toDateString())->orderByDesc('effective_from')->first();
    }

    public function addMarketRate(string $rate, Carbon $from, string $note, User $by): LoanMarketRate
    {
        $rate = str_replace([',', ' ', '%'], '', trim($rate));
        if (! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $rate) || Decimal::cmp($rate, '100') > 0) {
            throw ValidationException::withMessages(['rate' => 'The rate must be from 0 to 100 (up to 4 decimals).']);
        }

        return DB::transaction(function () use ($rate, $from, $note, $by) {
            $latest = LoanMarketRate::orderByDesc('effective_from')->lockForUpdate()->first();
            if ($latest && $from->copy()->startOfDay()->lte($latest->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'The effective date must be after the latest rate ('.$latest->effective_from->format('d M Y').').']);
            }
            $row = new LoanMarketRate;
            $row->forceFill(['effective_from' => $from->toDateString(), 'rate' => Decimal::make($rate), 'change_note' => mb_substr(trim($note), 0, 500),
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'created_by' => $by->id, 'created_by_name' => $by->name])->save();

            Audit::log('loan.market_rate_added', $row, "Market rate {$row->rate}% from ".$from->format('d M Y'), new: ['rate' => Decimal::plain((string) $row->rate)], context: ['reason' => $row->change_note]);

            return $row;
        });
    }

    /**
     * The fair value of every active loan the user can see (optionally one scheme) as at a date.
     *
     * @return array{lines: array<int, array>, totals: array{outstanding: string, nominal: string, fair_value: string, difference: string}}
     */
    public function compute(Carbon $asAt, string $marketRate, ?int $schemeId = null): array
    {
        [$dp, $mode] = LoanService::rounding();
        $loans = Loan::with(['employee', 'type', 'installments' => fn ($q) => $q->where('status', 'pending')])->where('status', 'active')
            ->when($schemeId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('scheme_id', $schemeId)))->get()
            ->sortBy(fn ($l) => $l->employee->employee_no.'|'.$l->reference)->values();
        $outstanding = $this->loans->outstandingFor($loans);

        $lines = [];
        $t = ['outstanding' => '0', 'nominal' => '0', 'fair_value' => '0'];
        foreach ($loans as $l) {
            $pv = LoanFairValue::present(
                $l->installments->map(fn ($i) => ['due_month' => $i->due_month->toDateString(), 'total' => $i->total()])->all(),
                $asAt->toDateString(), $marketRate, $dp, $mode,
            );
            $out = $outstanding[$l->id];
            $lines[] = [
                'loan' => $l, 'employee_no' => $l->employee->employee_no, 'employee_name' => $l->employee->display_name ?? $l->employee->full_name,
                'reference' => $l->reference, 'type' => $l->type->name, 'rate' => Decimal::plain((string) $l->current_rate), 'remaining' => $l->installments->count(),
                'outstanding' => $out, 'nominal' => $pv['nominal'], 'fair_value' => $pv['fair_value'], 'difference' => Decimal::round(Decimal::sub($pv['fair_value'], $out), $dp, $mode),
            ];
            $t['outstanding'] = Decimal::add($t['outstanding'], $out);
            $t['nominal'] = Decimal::add($t['nominal'], $pv['nominal']);
            $t['fair_value'] = Decimal::add($t['fair_value'], $pv['fair_value']);
        }
        $t['difference'] = Decimal::sub($t['fair_value'], $t['outstanding']);

        return ['lines' => $lines, 'totals' => array_map(fn ($v) => Decimal::round($v, $dp, $mode), $t)];
    }

    /** Saves a run for ALL loans (the caller must have all-scheme access, so the snapshot is complete). */
    public function save(Carbon $asAt, string $marketRate, ?string $note, User $by): LoanValuation
    {
        $r = $this->compute($asAt, $marketRate);

        return DB::transaction(function () use ($r, $asAt, $marketRate, $note, $by) {
            $v = new LoanValuation;
            $v->forceFill(['as_at' => $asAt->toDateString(), 'market_rate' => Decimal::make($marketRate), 'note' => $note ? mb_substr(trim($note), 0, 255) : null,
                'loan_count' => count($r['lines']), 'created_by' => $by->id, 'created_by_name' => $by->name])->save();

            foreach ($r['lines'] as $l) {
                (new LoanValuationLine)->forceFill([
                    'loan_valuation_id' => $v->id, 'loan_id' => $l['loan']->id, 'employee_id' => $l['loan']->employee_id, 'employee_no' => $l['employee_no'],
                    'employee_name' => $l['employee_name'], 'loan_reference' => $l['reference'], 'loan_type_name' => $l['type'], 'contractual_rate' => $l['rate'],
                    'remaining_installments' => $l['remaining'], 'outstanding_principal' => $l['outstanding'], 'nominal_remaining' => $l['nominal'], 'fair_value' => $l['fair_value'],
                ])->save();
            }

            Audit::log('loan.valuation_saved', $v, 'Loan fair value saved as at '.$asAt->format('d M Y'), context: ['market_rate' => $marketRate, 'loans' => $v->loan_count, 'totals' => $r['totals']]);

            return $v;
        });
    }

    /**
     * The fair value of a loan on the day it was paid out: its ORIGINAL schedule discounted at the market rate of that day. The difference to the
     * amount lent is the benefit given at the start. Only for loans created in the system (imported loans have no original schedule).
     *
     * @return array{rate: string, principal: string, fair_value: string, difference: string}|null
     */
    public function fairValueAtStart(Loan $loan): ?array
    {
        if ($loan->source !== 'manual' || $loan->status === 'cancelled') {
            return null;
        }
        $rate = $this->marketRateAt($loan->disbursed_on);
        if (! $rate) {
            return null;
        }
        [$dp, $mode] = LoanService::rounding();
        $first = $loan->installments()->where('schedule_version', 1)->get()
            ->map(fn ($i) => ['due_month' => $i->due_month->toDateString(), 'total' => $i->total()])->all();
        $pv = LoanFairValue::present($first, $loan->disbursed_on->toDateString(), Decimal::plain((string) $rate->rate), $dp, $mode);
        $principal = Decimal::round((string) $loan->principal, $dp, $mode);

        return ['rate' => Decimal::plain((string) $rate->rate), 'principal' => $principal, 'fair_value' => $pv['fair_value'], 'difference' => Decimal::round(Decimal::sub($pv['fair_value'], $principal), $dp, $mode)];
    }
}
