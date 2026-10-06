<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class AuditVerify extends Command
{
    protected $signature = 'audit:verify {--from= : Only check entries on/after this date}';
    protected $description = 'Check audit entries against their tamper-detection checksums';

    public function handle(): int
    {
        $bad = [];
        $total = 0;

        AuditLog::query()->when($this->option('from'), fn ($q, $d) => $q->where('occurred_at', '>=', $d))
            ->chunkById(1000, function ($rows) use (&$bad, &$total) {
                foreach ($rows as $row) {
                    $total++;
                    if (! $row->checksumIsValid()) {
                        $bad[] = $row->id;
                    }
                }
            });

        $this->info("Checked {$total} entries.");
        if ($bad) {
            $this->error(count($bad).' entries failed verification. First IDs: '.implode(', ', array_slice($bad, 0, 20)));
            return self::FAILURE;
        }
        $this->info('All checksums valid.');

        return self::SUCCESS;
    }
}
