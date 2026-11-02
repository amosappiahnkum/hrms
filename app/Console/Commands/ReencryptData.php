<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * After rotating APP_KEY (old key in APP_PREVIOUS_KEYS): re-encrypt every encrypted column with the new key, so the
 * old key can be dropped. Rows already on the new key are left alone, so it can be run again safely.
 */
class ReencryptData extends Command
{
    protected $signature = 'app:reencrypt {--dry-run : count what would change, change nothing}';

    protected $description = 'Re-encrypt stored data with the current APP_KEY after a key rotation';

    /** Every column holding Laravel-encrypted values: table => columns. Add new encrypted columns here. */
    private const COLUMNS = [
        'employee_pay_profiles' => ['account_number', 'mobile_money_number'],
        'payslips'              => ['payment_snapshot'],
        'users'                 => ['two_factor_secret', 'two_factor_recovery_codes'],
    ];

    public function handle(): int
    {
        $current = Crypt::getFacadeRoot();
        // Decrypting with the current key alone tells "already re-encrypted" apart from "on an old key".
        $currentOnly = new Encrypter($current->getKey(), config('app.cipher'));
        $failed = 0;

        foreach (self::COLUMNS as $table => $columns) {
            $changed = 0;
            DB::table($table)->select(['id', ...$columns])->orderBy('id')
                ->chunkById(200, function ($rows) use ($table, $columns, $current, $currentOnly, &$changed, &$failed) {
                    foreach ($rows as $row) {
                        $update = [];
                        foreach ($columns as $column) {
                            $value = $row->{$column};
                            if ($value === null || $value === '') {
                                continue;
                            }
                            try {
                                $currentOnly->decrypt($value, false);
                                continue;
                            } catch (DecryptException) {
                            }
                            try {
                                // Unserialize off both ways: the payload goes back exactly as it was stored.
                                $update[$column] = $current->encrypt($current->decrypt($value, false), false);
                            } catch (DecryptException) {
                                $failed++;
                                $this->error("{$table} #{$row->id} {$column}: not readable with APP_KEY or APP_PREVIOUS_KEYS.");
                            }
                        }
                        if ($update) {
                            $changed++;
                            if (!$this->option('dry-run')) {
                                DB::table($table)->where('id', $row->id)->update($update);
                            }
                        }
                    }
                });
            $this->line(($this->option('dry-run') ? 'Would re-encrypt' : 'Re-encrypted') . " {$changed} row(s) in {$table}.");
        }

        if ($failed) {
            $this->error("{$failed} value(s) couldn't be read: keep the old key in APP_PREVIOUS_KEYS until they're sorted out.");

            return self::FAILURE;
        }
        $this->info('Done. Once this runs cleanly on production, the old key can be removed from APP_PREVIOUS_KEYS.');

        return self::SUCCESS;
    }
}
