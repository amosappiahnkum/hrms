<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\PayComponent;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PayComponentsTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo(['configure-payroll']);
        Sanctum::actingAs($hr);
    }

    public function test_basic_salary_is_built_in_and_protected(): void
    {
        $basic = $this->getJson('/api/v1/payroll/pay-components')->assertOk()
            ->assertJsonPath('data.components.0.code', 'BASIC')
            ->assertJsonPath('data.components.0.is_system', true)
            ->json('data.components.0.uuid');

        $this->deleteJson("/api/v1/payroll/pay-components/{$basic}")->assertStatus(422);
        $this->putJson("/api/v1/payroll/pay-components/{$basic}", ['code' => 'BAS', 'name' => 'Basic pay', 'kind' => 'deduction', 'calculation' => 'fixed', 'rate' => 5])->assertOk();
        $basic = PayComponent::where('uuid', $basic)->first();
        $this->assertSame(['BASIC', 'Basic pay', 'earning', 'manual'], [$basic->code, $basic->name, $basic->kind->value, $basic->calculation->value]);
    }

    public function test_components_are_validated_by_how_they_are_calculated(): void
    {
        $post = fn (array $data) => $this->postJson('/api/v1/payroll/pay-components', $data + ['kind' => 'earning', 'taxable' => true]);

        $post(['code' => 'ot wd', 'name' => 'Weekday overtime', 'calculation' => 'hourly_multiplier'])->assertUnprocessable()->assertJsonValidationErrors('rate');
        $post(['code' => 'OFFSHORE', 'name' => 'Offshore day', 'calculation' => 'rate_per_unit', 'rate' => 50, 'currency' => 'usd'])->assertUnprocessable()->assertJsonValidationErrors('unit');

        $post(['code' => 'ot wd', 'name' => 'Weekday overtime', 'calculation' => 'hourly_multiplier', 'rate' => 1.5, 'currency' => 'USD', 'unit' => 'hour'])
            ->assertCreated()->assertJsonPath('data.code', 'OT_WD')->assertJsonPath('data.currency', null)->assertJsonPath('data.unit', null);
        $post(['code' => 'OFFSHORE', 'name' => 'Offshore day', 'calculation' => 'rate_per_unit', 'rate' => 50, 'currency' => 'usd', 'unit' => 'day'])
            ->assertCreated()->assertJsonPath('data.currency', 'USD');
        $post(['code' => 'OFFSHORE', 'name' => 'Dup', 'calculation' => 'manual'])->assertUnprocessable()->assertJsonValidationErrors('code');

        // A deduction is never subject to SSNIT; a manual component has no rate.
        $this->postJson('/api/v1/payroll/pay-components', ['code' => 'UNION', 'name' => 'Union dues', 'kind' => 'deduction', 'calculation' => 'manual', 'rate' => 9, 'ssnit_applicable' => true, 'taxable' => false])
            ->assertCreated()->assertJsonPath('data.ssnit_applicable', false)->assertJsonPath('data.rate', null);
    }
}
