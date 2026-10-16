<?php

namespace App\Console\Commands;

use App\Jobs\LoadAttendanceData;
use App\Services\AttendanceLoadService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class AttendanceLoadCommand extends Command
{
    protected $signature = 'attendance:load {--from= : First date (YYYY-MM-DD)} {--to= : Last date (YYYY-MM-DD)} {--scope=both : attendance, leave or both} {--queue : Dispatch to the queue instead of running now}';
    protected $description = 'Load attendance and/or leave from MiHCM for a date range (manual; never scheduled)';

    public function handle(AttendanceLoadService $service): int
    {
        foreach (['from', 'to'] as $o) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->option($o))) {
                $this->error("--{$o} is required, as YYYY-MM-DD.");

                return self::FAILURE;
            }
        }

        try {
            $run = $service->start((string) $this->option('scope'), Carbon::parse($this->option('from')), Carbon::parse($this->option('to')), 'console');
            if ($this->option('queue')) {
                LoadAttendanceData::dispatch($run->id);
                $this->info("Load #{$run->id} queued.");

                return self::SUCCESS;
            }
            $service->execute($run);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $run->refresh();
        $this->info("Load #{$run->id} {$run->status}.");
        foreach ($run->counts ?? [] as $kind => $c) {
            $this->line(sprintf('  %-10s fetched %d, new %d, updated %d, unchanged %d, missing %d, unmatched %d, skipped %d', ucfirst($kind),
                $c['fetched'], $c['created'], $c['updated'], $c['unchanged'], $c['missing'], $c['unmatched'], $c['skipped']));
        }
        foreach ($run->quality ?? [] as $q) {
            if ($q['severity'] === 'warning') {
                $this->warn("  ! {$q['label']}: {$q['count']}");
            }
        }

        return self::SUCCESS;
    }
}
