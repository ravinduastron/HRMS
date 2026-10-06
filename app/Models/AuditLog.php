<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only. Never uses the Auditable trait (it would log itself). */
class AuditLog extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'occurred_at' => 'datetime',
        'old_values'  => 'array',
        'new_values'  => 'array',
        'context'     => 'array',
    ];

    public function getConnectionName(): ?string
    {
        return config('audit.connection') ?: parent::getConnectionName();
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForModel(Builder $q, Model $model): Builder
    {
        return $q->where('auditable_type', $model->getMorphClass())->where('auditable_id', (string) $model->getKey());
    }

    public function scopeFilter(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($f['user'] ?? null, fn ($q, $v) => $q->where('user_name', 'like', "%{$v}%"))
            ->when($f['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($f['model'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('auditable_id', $v)->orWhere('auditable_label', 'like', "%{$v}%")->orWhere('description', 'like', "%{$v}%")));
    }

    public function modelName(): string
    {
        return $this->auditable_type ? class_basename($this->auditable_type) : '—';
    }

    /* ---------- tamper detection ---------- */

    public static function checksumFor(array $a): string
    {
        $payload = implode('|', [
            Carbon::parse($a['occurred_at'])->format('Y-m-d H:i:s.u'),
            $a['user_id'] ?? '', $a['event'], $a['auditable_type'] ?? '', $a['auditable_id'] ?? '',
            self::canonical($a['old_values'] ?? null), self::canonical($a['new_values'] ?? null),
        ]);

        return hash_hmac('sha256', $payload, config('audit.checksum_key') ?: config('app.key'));
    }

    public function checksumIsValid(): bool
    {
        return hash_equals($this->checksum, self::checksumFor($this->only([
            'occurred_at', 'user_id', 'event', 'auditable_type', 'auditable_id', 'old_values', 'new_values',
        ])));
    }

    private static function canonical(mixed $v): string
    {
        $sort = function ($x) use (&$sort) {
            if (! is_array($x)) return $x;
            $x = array_map($sort, $x);
            if (! array_is_list($x)) ksort($x); // MySQL JSON reorders keys; normalise
            return $x;
        };

        return json_encode($sort($v ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
