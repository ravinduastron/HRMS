<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Add `use Auditable;` to any model to log create / update / delete / restore with old & new values.
 *
 * Optional properties on the model:
 *   protected array $auditHidden  = ['nic', 'bank_account']; // value never stored; entry says "[hidden]" (encrypted/hashed casts are automatic)
 *   protected array $auditExclude = ['last_seen_at'];        // changes to these are ignored
 * Optional method:
 *   public function auditLabel(): string                     // human label stored with each entry
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => Audit::modelEvent($m, 'created'));
        static::updated(fn ($m) => Audit::modelEvent($m, 'updated'));
        static::deleted(fn ($m) => Audit::modelEvent($m, 'deleted'));

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class))) {
            static::restored(fn ($m) => Audit::modelEvent($m, 'restored'));
        }
    }
}
