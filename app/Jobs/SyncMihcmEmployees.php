<?php

namespace App\Jobs;

use App\Models\EmployeeSyncRun;
use App\Services\Mihcm\EmployeeSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class SyncMihcmEmployees implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;      // a failed sync is recorded and re-run deliberately, not retried blindly
    public int $timeout = 1800;

    public function __construct(public int $runId) {}

    public function handle(EmployeeSyncService $service): void
    {
        $service->execute(EmployeeSyncRun::findOrFail($this->runId));
    }

    public function failed(?Throwable $e): void // also covers worker timeouts / kills
    {
        EmployeeSyncRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => Str::limit($e?->getMessage() ?? 'Job failed', 2000)]);
    }
}
