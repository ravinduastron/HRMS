<?php

namespace App\Services\Mihcm;

use Carbon\Carbon;
use Throwable;

/** The ONLY place that knows MiHCM field names. API field => employees column. */
class EmployeeMapper
{
    public function map(array $e): array
    {
        $levels = config('mihcm.org_levels');
        $org = collect(is_array($e['orgStructure'] ?? null) ? $e['orgStructure'] : [])->keyBy(fn ($o) => (int) ($o['level'] ?? 0));
        $rp = is_array($e['reportingPerson'] ?? null) ? $e['reportingPerson'] : [];

        return [
            'employee_no' => $this->str($e['employeeNumber'] ?? null, 30), // the match key (MiHCM sends no other stable id)

            'emp_title'    => $this->str($e['titleName'] ?? null, 20),
            'display_name' => $this->str($e['displayName'] ?? null, 150),
            'calling_name' => $this->str($e['firstName'] ?? null, 100),
            'middle_name'  => $this->str($e['middleName'] ?? null, 100),
            'last_name'    => $this->str($e['lastName'] ?? null, 100),
            'surname'      => $this->str($e['surname'] ?? null, 100),
            'full_name'    => $this->str($e['fullName'] ?? null, 255),
            'nic_no'       => $this->str($e['nicNumber'] ?? null, 20),
            'birthday'     => $this->date($e['dateOfBirth'] ?? null),
            'gender'       => $this->str($e['genderName'] ?? null, 20),

            'mobile' => $this->str($e['mobileNumber'] ?? null, 30),
            'email'  => $this->str($e['email'] ?? null, 255),
            'permanent_address_1'   => $this->str($e['permanentAddress1'] ?? null, 255),
            'permanent_address_2'   => $this->str($e['permanentAddress2'] ?? null, 255),
            'permanent_address_3'   => $this->str($e['permanentAddress3'] ?? null, 255),
            'permanent_postal_code' => $this->str($e['permanentPostalCode'] ?? null, 20),
            'permanent_district'    => $this->str($e['permanentDistrict'] ?? null, 100),
            'permanent_country'     => $this->str($e['permanentCountry'] ?? null, 100),
            'residential_address_1'   => $this->str($e['residentialAddress1'] ?? null, 255),
            'residential_address_2'   => $this->str($e['residentialAddress2'] ?? null, 255),
            'residential_address_3'   => $this->str($e['residentialAddress3'] ?? null, 255),
            'residential_postal_code' => $this->str($e['residentialPostalCode'] ?? null, 20),
            'residential_district'    => $this->str($e['residentialDistrict'] ?? null, 100),
            'residential_country'     => $this->str($e['residentialCountry'] ?? null, 100),

            'date_joined'             => $this->date($e['dateOfAppointment'] ?? null),
            'contract_expiry_date'    => $this->date($e['contractExpiryDate'] ?? null),
            'resigned_date'           => $this->date($e['resignedDate'] ?? null),
            'resignation_effect_date' => $this->date($e['resignationEffectDate'] ?? null),
            'last_working_date'       => $this->date($e['lastWorkingDate'] ?? null),
            'emp_type'    => $this->str($e['employmentType'] ?? null, 50),
            'ct_name'     => $this->str($e['jobCategoryName'] ?? null, 100),
            'dsg_name'    => $this->str($e['designationName'] ?? null, 150),
            'grade_name'  => $this->str($e['gradeName'] ?? null, 100),
            'scheme_name' => $this->str($e['schemeName'] ?? null, 100),
            'msps_no'     => $this->str($e['epfNo'] ?? null, 30),

            'mihcm_department_id' => $this->str($org->get($levels['department'])['levelCode'] ?? null, 50),
            'department'          => $this->str($org->get($levels['department'])['levelName'] ?? null, 150),
            'mihcm_section_id'    => $this->str($org->get($levels['section'])['levelCode'] ?? null, 50),
            'mihcm_section'       => $this->str($org->get($levels['section'])['levelName'] ?? null, 150),
            'team'                => $this->str($org->get($levels['team'])['levelName'] ?? null, 150),
            // Key order code,name,level matches MySQL's JSON key ordering, so unchanged rows never look "changed".
            'mihcm_org_structure' => $org->isEmpty() ? null : $org->sortKeys()->map(fn ($o) => [
                'code' => $this->str($o['levelCode'] ?? null, 50), 'name' => $this->str($o['levelName'] ?? null, 150), 'level' => (int) ($o['level'] ?? 0),
            ])->values()->all(),

            'reporting_person_employee_no' => $this->str($rp['employeeNumber'] ?? $e['reportingPersonEmployeeNumber'] ?? null, 30),
            'reporting_person_name'        => $this->str($rp['displayName2'] ?? $rp['displayName'] ?? $rp['fullName'] ?? null, 150),
            'reporting_person_email'       => $this->str($rp['email'] ?? null, 255),

            'mihcm_active'  => filter_var($e['active'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'mihcm_deleted' => filter_var($e['deleted'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function str(mixed $v, int $max): ?string
    {
        if ($v === null || is_array($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function date(mixed $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            $d = Carbon::parse($v);
        } catch (Throwable) {
            return null;
        }

        return $d->year < 1900 ? null : $d->format('Y-m-d'); // MiHCM uses 0001-01-01 for "no date"
    }
}
