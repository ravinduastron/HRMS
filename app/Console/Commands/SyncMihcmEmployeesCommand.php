<?php

namespace App\Console\Commands;

use App\Jobs\SyncMihcmEmployees;
use App\Services\Mihcm\EmployeeSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncMihcmEmployeesCommand extends Command
{
    protected $signature = 'mihcm:sync-employees {--queue : Dispatch to the queue instead of running now}';
    protected $description = 'Sync employees from MiHCM';

    public function handle(EmployeeSyncService $service): int
    {
        try {
            $run = $service->start('scheduled');
            if ($this->option('queue')) {
                SyncMihcmEmployees::dispatch($run->id);
                $this->info("Sync #{$run->id} queued.");
                return self::SUCCESS;
            }
            $service->execute($run);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $run->refresh();
        $this->info("Sync #{$run->id}: {$run->fetched_count} fetched, {$run->created_count} new, {$run->updated_count} updated, {$run->deactivated_count} deactivated, {$run->skipped_count} skipped.");

        return self::SUCCESS;
    }
}
