<?php

namespace App\Support;

use App\Jobs\WriteAuditLog;
use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class Audit
{
    private static int $paused = 0;

    /** Skip auditing inside the callback (bulk imports, seeders). Use sparingly and log a summary instead. */
    public static function withoutAuditing(callable $callback): mixed
    {
        self::$paused++;
        try {
            return $callback();
        } finally {
            self::$paused--;
        }
    }

    /** Manual entry for anything that isn't a plain model change (approvals, exports, role syncs, auth...). */
    public static function log(
        string $event,
        ?Model $subject = null,
        ?string $description = null,
        ?array $old = null,
        ?array $new = null,
        array $context = [],
        ?Authenticatable $actor = null,
    ): void {
        $hidden = $subject ? self::hiddenFor($subject) : config('audit.hidden');

        self::write($event, $subject, $description, self::sanitize($old, $hidden), self::sanitize($new, $hidden), $context, $actor);
    }

    /** Called by the Auditable trait. */
    public static function modelEvent(Model $m, string $event): void
    {
        if (self::$paused) {
            return;
        }

        $exclude = array_merge(config('audit.exclude'), self::prop($m, 'auditExclude'));
        $old = $new = null;

        switch ($event) {
            case 'created':
                $new = Arr::except($m->getAttributes(), $exclude);
                break;
            case 'updated':
                $changes = Arr::except($m->getChanges(), $exclude);
                if (! $changes) {
                    return;
                }
                $new = $changes;
                $old = collect($changes)->map(fn ($v, $k) => $m->getRawOriginal($k))->all();
                break;
            case 'deleted':
                $old = Arr::except($m->getAttributes(), $exclude);
                break;
        }

        $hidden = self::hiddenFor($m);
        self::write($event, $m, null, self::sanitize($old, $hidden), self::sanitize($new, $hidden), []);
    }

    /* ------------------------------------------------------------------ */

    private static function write(string $event, ?Model $subject, ?string $description, ?array $old, ?array $new, array $context, ?Authenticatable $actor = null): void
    {
        if (! config('audit.enabled')) {
            return;
        }

        $request = request();
        $actor ??= auth()->user();

        $attributes = [
            'occurred_at'     => now(),
            'batch_id'        => self::batchId(),
            'user_id'         => $actor?->getAuthIdentifier(),
            'user_name'       => $actor?->name ?? null,
            'event'           => $event,
            'auditable_type'  => $subject?->getMorphClass(),
            'auditable_id'    => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'auditable_label' => $subject ? self::labelFor($subject) : null,
            'description'     => $description,
            'old_values'      => $old ?: null,
            'new_values'      => $new ?: null,
            'context'         => array_filter(['source' => app()->runningInConsole() ? 'console' : 'web'] + $context) ?: null,
            'method'          => app()->runningInConsole() ? null : $request->method(),
            'url'             => app()->runningInConsole() ? null : Str::limit($request->url(), 2000, ''), // no query string
            'ip_address'      => $request->ip(),
            'user_agent'      => Str::limit((string) $request->userAgent(), 500, ''),
        ];
        $attributes['checksum'] = AuditLog::checksumFor($attributes);

        $save = function () use ($attributes) {
            if ($queue = config('audit.queue')) {
                WriteAuditLog::dispatch($attributes)->onQueue($queue);
                return;
            }
            AuditLog::create($attributes);
        };

        if (self::usesSeparateConnection() || config('audit.queue')) {
            // Different database (or queue): log only once the business transaction has really committed,
            // and never let an audit failure break the user's action (it is reported instead).
            DB::afterCommit(function () use ($save) {
                try {
                    $save();
                } catch (Throwable $e) {
                    report($e);
                }
            });
        } else {
            // Same database: write inline, inside the current transaction. Atomic: no change without its log entry.
            $save();
        }
    }

    private static function usesSeparateConnection(): bool
    {
        $c = config('audit.connection');

        return $c && $c !== config('database.default');
    }

    private static function batchId(): string
    {
        $bag = request()->attributes;
        if (! $bag->has('audit_batch')) {
            $bag->set('audit_batch', (string) Str::uuid());
        }

        return $bag->get('audit_batch');
    }

    private static function hiddenFor(Model $m): array
    {
        $auto = collect($m->getCasts())
            ->filter(fn ($cast) => is_string($cast) && Str::startsWith($cast, ['encrypted', 'hashed']))
            ->keys()->all();

        return array_unique(array_merge(config('audit.hidden'), self::prop($m, 'auditHidden'), $auto));
    }

    private static function sanitize(?array $values, array $hidden): ?array
    {
        if ($values === null) {
            return null;
        }
        $max = config('audit.max_value_length');

        return collect($values)->map(function ($v, $k) use ($hidden, $max) {
            if (in_array($k, $hidden, true)) {
                return config('audit.mask');
            }

            return is_string($v) && mb_strlen($v) > $max ? mb_substr($v, 0, $max).'… [truncated]' : $v;
        })->all();
    }

    private static function labelFor(Model $m): ?string
    {
        $label = method_exists($m, 'auditLabel') ? $m->auditLabel() : ($m->getAttribute('name') ?? $m->getAttribute('title'));

        return $label ? Str::limit((string) $label, 250, '') : null;
    }

    private static function prop(Model $m, string $name): array
    {
        return property_exists($m, $name) ? (array) $m->{$name} : [];
    }
}
