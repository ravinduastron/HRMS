<?php

namespace App\Jobs;

use App\Models\AttendanceLoadRun;
use App\Services\AttendanceLoadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class LoadAttendanceData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;      // a failed load is recorded and re-run deliberately, not retried blindly
    public int $timeout = 1800;

    public function __construct(public int $runId) {}

    public function handle(AttendanceLoadService $service): void
    {
        $service->execute(AttendanceLoadRun::findOrFail($this->runId));
    }

    public function failed(?Throwable $e): void // also covers worker timeouts / kills
    {
        AttendanceLoadRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => Str::limit($e?->getMessage() ?? 'Job failed', 2000)]);
    }
}
