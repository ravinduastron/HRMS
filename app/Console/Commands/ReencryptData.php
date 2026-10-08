<?php

namespace App\Console\Commands;

use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ReencryptData extends Command
{
    protected $signature = 'data:reencrypt {--dry-run : Only check that every value can be decrypted}';
    protected $description = 'Re-encrypt encrypted columns with the current APP_KEY (run after rotating the key)';

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        $failed = 0;
        $done = 0;

        foreach (config('reencrypt.tables') as $table => $cfg) {
            foreach ($cfg['columns'] as $column) {
                DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(200, function ($rows) use ($table, $column, $dry, &$failed, &$done) {
                    DB::transaction(function () use ($rows, $table, $column, $dry, &$failed, &$done) {
                        foreach ($rows as $row) {
                            try {
                                $plain = Crypt::decryptString($row->{$column}); // current key, then APP_PREVIOUS_KEYS
                            } catch (DecryptException) {
                                $failed++;
                                $this->error("{$table}.{$column} id {$row->id}: cannot decrypt with the current or previous keys.");
                                continue;
                            }
                            if (! $dry) {
                                DB::table($table)->where('id', $row->id)->update([$column => Crypt::encryptString($plain)]);
                            }
                            $done++;
                        }
                    });
                });
            }
        }

        if ($failed) {
            $this->error("{$failed} value(s) could not be decrypted. Fix APP_PREVIOUS_KEYS and run again; nothing was lost.");
            return self::FAILURE;
        }
        if ($dry) {
            $this->info("Dry run OK: {$done} values can be decrypted.");
            return self::SUCCESS;
        }

        foreach (config('reencrypt.tables') as $cfg) {
            if (isset($cfg['after'])) {
                $this->info('Post-step '.class_basename($cfg['after'][0]).'::'.$cfg['after'][1].': '.call_user_func($cfg['after']).' rows');
            }
        }

        Audit::log('encryption.rotated', null, "Re-encrypted {$done} values with the current application key");
        $this->info("Re-encrypted {$done} values.");

        return self::SUCCESS;
    }
}
