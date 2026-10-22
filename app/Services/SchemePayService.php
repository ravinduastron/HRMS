<?php

namespace App\Services;

use App\Models\Scheme;
use App\Models\SchemePaySetting;
use App\Models\User;
use App\Payroll\PayCalendar;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** All scheme pay-settings rules live here (a future approval engine hooks into save()). Every change adds a new immutable version. */
class SchemePayService
{
    public function save(Scheme $scheme, array $settings, string $timing, string $disbursement, Carbon $effectiveFrom, string $note, User $by): SchemePaySetting
    {
        $errors = PayCalendar::validate($settings);
        if ($errors) {
            throw ValidationException::withMessages(['cycles' => $errors]);
        }
        if (! array_key_exists($timing, PayCalendar::TIMINGS)) {
            throw ValidationException::withMessages(['statutory_timing' => 'Choose when EPF / MSPS / ETF are deducted.']);
        }
        if (! in_array($disbursement, ['bank', 'cash'], true)) {
            throw ValidationException::withMessages(['disbursement' => 'Choose bank or cash.']);
        }

        return DB::transaction(function () use ($scheme, $settings, $timing, $disbursement, $effectiveFrom, $note, $by) {
            Scheme::whereKey($scheme->id)->lockForUpdate()->firstOrFail(); // serialise changes per scheme

            $latest = $scheme->paySettings()->first();
            if ($latest && $effectiveFrom->copy()->startOfDay()->lte($latest->effective_from)) {
                throw ValidationException::withMessages(['effective_from' => 'The effective date must be after the latest version ('.$latest->effective_from->format('d M Y').').']);
            }

            $version = new SchemePaySetting;
            $version->forceFill([
                'scheme_id' => $scheme->id, 'effective_from' => $effectiveFrom->toDateString(),
                'cycles' => $settings['cycles'], 'windows' => $settings['windows'], 'pay_date' => $settings['pay_date'],
                'statutory_timing' => $timing, 'disbursement' => $disbursement, 'change_note' => mb_substr(trim($note), 0, 500),
                'approval_status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), // approval engine hooks in here later
                'created_by' => $by->id, 'created_by_name' => $by->name,
            ])->save();

            Audit::log('scheme.pay_settings_added', $scheme, "Pay settings of {$scheme->name} effective {$effectiveFrom->format('d M Y')}",
                new: ['cycles' => collect($settings['cycles'])->map(fn ($c) => PayCalendar::describeCycle($c))->all(), 'statutory_timing' => $timing, 'disbursement' => $disbursement],
                context: ['version_id' => $version->id, 'effective_from' => $effectiveFrom->toDateString(), 'reason' => $version->change_note]);

            return $version;
        });
    }
}
