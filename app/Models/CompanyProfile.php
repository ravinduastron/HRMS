<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** The legal entity (one row). Edited in place; the audit log keeps old and new values. */
class CompanyProfile extends Model
{
    use Auditable;

    protected $guarded = [];

    public function auditLabel(): string
    {
        return 'Company profile';
    }

    /** The saved profile, or an unsaved one prefilled from config until the first save. */
    public static function current(): static
    {
        return static::query()->first() ?? new static(['legal_name' => config('payroll.company')]);
    }
}
