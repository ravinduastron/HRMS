<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class AuditPrune extends Command
{
    protected $signature = 'audit:prune {--days= : Override AUDIT_RETENTION_DAYS}';
    protected $description = 'Delete audit entries older than the retention period (archive them first if required)';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('audit.retention_days');
        if (! $days) {
            $this->warn('No retention period set (AUDIT_RETENTION_DAYS). Nothing deleted.');
            return self::SUCCESS;
        }

        // Query-builder delete on purpose: the model blocks deletes. Needs a DB user with DELETE rights on audit_logs.
        $count = AuditLog::query()->where('occurred_at', '<', now()->subDays((int) $days))->toBase()->delete();
        $this->info("Deleted {$count} entries older than {$days} days.");

        return self::SUCCESS;
    }
}
