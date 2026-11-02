<?php

namespace Tests\Feature\Payroll;

use App\Models\Payroll\EmployeePayProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/**
 * Rotating APP_KEY: the old key goes in APP_PREVIOUS_KEYS so encrypted pay details still read, `app:reencrypt` moves
 * them to the new key, and the payment fingerprints keep their own key (PAYMENT_FINGERPRINT_KEY).
 */
class KeyRotationTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
    }

    private function profile(User $user): EmployeePayProfile
    {
        return EmployeePayProfile::create([
            'employee_id' => $user->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 5000,
            'payment_method' => 'bank', 'bank_name' => 'GCB', 'account_number' => '1011-2233', 'mobile_money_number' => '0244000111',
        ]);
    }

    private function useKeys(string $key, array $previous = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private static function newKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function test_the_fingerprint_command_recomputes_them_with_the_current_key(): void
    {
        $profile = $this->profile($this->userWithRole('staff'));

        config(['kazi360.payment_fingerprint_key' => 'a-new-fingerprint-secret']);
        $this->artisan('payroll:account-fingerprints')->assertSuccessful();

        $profile->refresh();
        $this->assertSame(EmployeePayProfile::fingerprint('1011-2233', 'bank'), $profile->account_fingerprint);
        $this->assertSame(EmployeePayProfile::fingerprint('0244000111', 'mobile_money'), $profile->mobile_money_fingerprint);
    }

    public function test_a_rotated_key_reads_old_data_and_reencrypt_moves_it_to_the_new_key(): void
    {
        $old = config('app.key');
        // Fingerprints pinned to their own key first, so rotating APP_KEY doesn't change them.
        config(['kazi360.payment_fingerprint_key' => $old]);
        $user = $this->userWithRole('staff');
        $profile = $this->profile($user);
        $fingerprint = $profile->account_fingerprint;
        $user->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')])->save();

        // Rotate: the old key kept as a previous key, so everything still reads.
        $new = self::newKey();
        $this->useKeys($new, [$old]);
        $this->assertSame('1011-2233', $profile->fresh()->account_number);

        $this->artisan('app:reencrypt')->assertSuccessful();

        // With the old key gone, everything still reads: it was all re-encrypted with the new one.
        $this->useKeys($new);
        $profile = $profile->fresh();
        $this->assertSame(['1011-2233', '0244000111'], [$profile->account_number, $profile->mobile_money_number]);
        $this->assertSame('JBSWY3DPEHPK3PXP', decrypt($user->fresh()->two_factor_secret));
        // …and the duplicate-account check still finds them.
        $this->assertSame($fingerprint, $profile->account_fingerprint);
        $this->assertTrue($profile->is(EmployeePayProfile::sharedWith(['payment_method' => 'bank', 'account_number' => '1011 2233'], [0])));
    }
}
