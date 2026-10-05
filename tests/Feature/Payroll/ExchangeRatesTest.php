<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\ExchangeRate;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class ExchangeRatesTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_a_rate_per_currency_and_year_converts_pay_and_the_base_needs_none(): void
    {
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['configure-payroll']);
        Sanctum::actingAs($hr);

        $uuid = $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'usd', 'year' => 2026, 'rate' => 15.2])
            ->assertCreated()->assertJsonPath('data.currency', 'USD')->json('data.uuid');
        $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'USD', 'year' => 2026, 'rate' => 16])
            ->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'GHS', 'year' => 2026, 'rate' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'USD', 'year' => 2027, 'rate' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('rate');

        $this->assertSame(15.2, ExchangeRate::for('usd', 2026));
        $this->assertNull(ExchangeRate::for('USD', 2027));
        $this->assertSame(1.0, ExchangeRate::for('GHS', 2027));

        $this->putJson("/api/v1/payroll/exchange-rates/{$uuid}", ['currency' => 'USD', 'year' => 2026, 'rate' => 15.75])->assertOk();
        $this->getJson('/api/v1/payroll/exchange-rates')->assertJsonPath('data.base_currency', 'GHS')->assertJsonPath('data.rates.0.rate', 15.75);
    }
}
