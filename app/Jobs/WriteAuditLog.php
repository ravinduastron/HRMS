<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WriteAuditLog implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public array $attributes) {}

    public function handle(): void
    {
        AuditLog::create($this->attributes);
    }
}
