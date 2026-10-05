<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\StatutoryRateSet;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class StatutoryRatesTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();

        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['configure-payroll', 'view-payroll']);
        Sanctum::actingAs($hr);
    }

    private function rates(array $overrides = []): array
    {
        $set = StatutoryRateSet::first();

        return $overrides + [
            'name'           => 'Ghana 2026',
            'effective_from' => '2026-01-01',
            'ssnit'          => $set->ssnit,
            'paye_bands'     => $set->paye_bands,
            'reliefs'        => $set->reliefs,
            'tier3_relief_limit_percent' => 16.5,
            'overtime_tax'   => $set->overtime_tax,
            'bonus_tax'      => $set->bonus_tax,
        ];
    }

    public function test_a_ghana_starting_set_is_seeded_unconfirmed(): void
    {
        $this->getJson('/api/v1/payroll/statutory-rates')->assertOk()
            ->assertJsonCount(1, 'data.sets')
            ->assertJsonPath('data.sets.0.confirmed_at', null)
            ->assertJsonPath('data.sets.0.ssnit.employee_rate', 5.5)
            ->assertJsonPath('data.sets.0.paye_bands.6', ['limit' => null, 'rate' => 35]);

        // Seeding again adds nothing.
        $this->seed(\Database\Seeders\StatutoryRatesSeeder::class);
        $this->assertSame(1, StatutoryRateSet::count());
    }

    public function test_new_rates_from_a_date_are_checked_confirmed_and_editing_unconfirms(): void
    {
        $bands = [['limit' => 500, 'rate' => 0], ['limit' => null, 'rate' => 30], ['limit' => 100, 'rate' => 35]];
        $this->postJson('/api/v1/payroll/statutory-rates', $this->rates(['paye_bands' => $bands]))
            ->assertUnprocessable()->assertJsonValidationErrors('paye_bands.1.limit');
        $this->postJson('/api/v1/payroll/statutory-rates', $this->rates(['ssnit' => ['employee_rate' => 5.5, 'employer_rate' => 13, 'tier1_rate' => 13.5, 'tier2_rate' => 4, 'max_insurable_earnings' => 61000]]))
            ->assertUnprocessable()->assertJsonValidationErrors('ssnit.tier2_rate');
        $this->postJson('/api/v1/payroll/statutory-rates', $this->rates(['effective_from' => '2024-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');

        $uuid = $this->postJson('/api/v1/payroll/statutory-rates', $this->rates())->assertCreated()->json('data.uuid');
        $this->travelTo('2026-03-15');
        $this->getJson('/api/v1/payroll/statutory-rates')->assertJsonPath('data.current_uuid', $uuid)->assertJsonPath('data.sets.0.uuid', $uuid);
        $this->assertSame('Ghana (starting set: verify before use)', StatutoryRateSet::inForceOn('2025-06-30')->name);

        $this->postJson("/api/v1/payroll/statutory-rates/{$uuid}/confirm")->assertOk()->assertJsonPath('data.confirmed_by', fn ($n) => $n !== null);
        $this->deleteJson("/api/v1/payroll/statutory-rates/{$uuid}")->assertStatus(422);

        $this->putJson("/api/v1/payroll/statutory-rates/{$uuid}", $this->rates(['name' => 'Ghana 2026 (revised)']))->assertOk()->assertJsonPath('data.confirmed_at', null);
        $this->deleteJson("/api/v1/payroll/statutory-rates/{$uuid}")->assertOk();
    }

    public function test_viewers_read_and_only_configurers_change_rates(): void
    {
        $viewer = $this->userWithRole('staff');
        $viewer->givePermissionTo('view-payroll');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/payroll/statutory-rates')->assertOk();
        $this->postJson('/api/v1/payroll/statutory-rates', $this->rates())->assertForbidden();
    }
}
