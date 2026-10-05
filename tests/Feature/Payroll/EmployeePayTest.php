<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class EmployeePayTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['prepare-payroll', 'view-payroll', 'configure-payroll']);
        $this->staff = $this->userWithRole('staff');
    }

    private function turnOn(string $flag = 'enabled'): void
    {
        Setting::where('key', "features.payroll.{$flag}")->update(['value' => true]);
        app(SettingService::class)->refreshCache();
    }

    private function details(array $overrides = []): array
    {
        return $overrides + [
            'mode' => 'correct', 'basic_salary' => 4500, 'payment_method' => 'bank',
            'bank_name' => 'GCB Bank', 'bank_branch' => 'Takoradi', 'account_name' => 'Ama Mensah', 'account_number' => '1011223344556',
        ];
    }

    public function test_pay_details_are_saved_validated_and_payment_numbers_kept_secret(): void
    {
        $employee = $this->staff->employee;
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/payroll/employees')->assertForbidden();
        $this->turnOn('loans'); // loans alone need pay details too

        $this->getJson('/api/v1/payroll/employees?status=no_profile')->assertOk()->assertJsonFragment(['missing' => ['Pay details']]);

        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $this->details(['account_number' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors('account_number');
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $this->details(['payment_method' => 'mobile_money']))
            ->assertUnprocessable()->assertJsonValidationErrors(['mobile_money_provider', 'mobile_money_number']);

        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $this->details(['ssnit_number' => 'C123456789012']))->assertOk()
            ->assertJsonPath('data.profile.account_number', '1011223344556')
            ->assertJsonPath('data.profile.currency', 'GHS')
            ->assertJsonPath('data.employee.ssnit_number', 'C123456789012')
            ->assertJsonPath('data.missing', ['TIN / Ghana Card']);

        // Encrypted at rest, and never in the audit log.
        $raw = DB::table('employee_pay_profiles')->value('account_number');
        $this->assertNotSame('1011223344556', $raw);
        $this->assertFalse(Activity::where('subject_type', (new EmployeePayProfile())->getMorphClass())->get()->contains(fn ($a) => str_contains(json_encode($a->properties), '1011223344556')));

        // Self-service: read-only, masked.
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/payroll/my-pay-details')->assertOk()
            ->assertJsonPath('data.profile.account_number', '•••• 4556')
            ->assertJsonPath('data.options', null);
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $this->details())->assertForbidden();
    }

    public function test_a_raise_from_a_date_keeps_the_old_details_for_earlier_periods(): void
    {
        $this->turnOn();
        $employee = $this->staff->employee;
        Sanctum::actingAs($this->hr);
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $this->details(['effective_from' => '2026-01-01']))->assertOk();

        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", ['mode' => 'change', 'effective_from' => '2025-12-01', 'basic_salary' => 5000, 'payment_method' => 'bank', 'bank_name' => 'GCB Bank', 'account_number' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('effective_from');

        $this->travelTo('2026-05-10');
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", ['mode' => 'change', 'effective_from' => '2026-07-01', 'basic_salary' => 5200, 'payment_method' => 'bank', 'bank_name' => 'GCB Bank', 'account_number' => '1011223344556'])
            ->assertOk()
            ->assertJsonPath('data.profile.basic_salary', 4500)
            ->assertJsonPath('data.history.0.effective_from', '2026-07-01')
            ->assertJsonPath('data.history.0.basic_salary', 5200)
            ->assertJsonPath('data.history.0.account_name', 'Ama Mensah'); // carried over

        $this->travelTo('2026-07-15');
        $this->getJson("/api/v1/payroll/employees/{$employee->uuid}")->assertJsonPath('data.profile.basic_salary', 5200);
        $this->assertEquals(4500, EmployeePayProfile::inForceOn('2026-06-30')->value('basic_salary'));
    }

    public function test_reliefs_must_exist_in_the_statutory_rates_within_their_limits(): void
    {
        $this->turnOn();
        $employee = $this->staff->employee;
        Sanctum::actingAs($this->hr);

        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $this->details(['reliefs' => [['code' => 'yacht', 'units' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('reliefs.0.code');
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $this->details(['reliefs' => [['code' => 'children', 'units' => 4]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('reliefs.0.units');
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/profile", $this->details(['reliefs' => [['code' => 'children', 'units' => 2], ['code' => 'marriage']]]))
            ->assertOk()->assertJsonCount(2, 'data.profile.reliefs');
    }

    public function test_recurring_components_are_given_at_their_rate_or_an_own_amount(): void
    {
        $this->turnOn();
        $employee = $this->staff->employee;
        Sanctum::actingAs($this->hr);
        $transport = PayComponent::create(['code' => 'TRANSPORT', 'name' => 'Transport', 'kind' => 'earning', 'calculation' => 'fixed', 'rate' => 300, 'recurring' => true]);
        $housing = PayComponent::create(['code' => 'HOUSING', 'name' => 'Housing', 'kind' => 'earning', 'calculation' => 'manual', 'recurring' => true]);
        $bonus = PayComponent::create(['code' => 'BONUS', 'name' => 'Bonus', 'kind' => 'earning', 'calculation' => 'manual', 'recurring' => false]);

        $this->postJson("/api/v1/payroll/employees/{$employee->uuid}/components", ['pay_component_uuid' => $bonus->uuid, 'effective_from' => '2026-01-01', 'amount' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('pay_component_uuid');
        $this->postJson("/api/v1/payroll/employees/{$employee->uuid}/components", ['pay_component_uuid' => $housing->uuid, 'effective_from' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->postJson("/api/v1/payroll/employees/{$employee->uuid}/components", ['pay_component_uuid' => $transport->uuid, 'effective_from' => '2026-01-01'])
            ->assertCreated()->assertJsonPath('data.components.0.amount', null)->assertJsonPath('data.components.0.component.rate', 300);
        $uuid = $this->postJson("/api/v1/payroll/employees/{$employee->uuid}/components", ['pay_component_uuid' => $housing->uuid, 'effective_from' => '2026-01-01', 'amount' => 800])
            ->assertCreated()->json('data.components.1.uuid');

        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/components/{$uuid}", ['amount' => 900, 'effective_from' => '2026-01-01', 'effective_to' => '2025-12-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('effective_to');
        $this->putJson("/api/v1/payroll/employees/{$employee->uuid}/components/{$uuid}", ['amount' => 900, 'effective_from' => '2026-01-01'])->assertOk()->assertJsonPath('data.components.1.amount', 900);
        $this->deleteJson("/api/v1/payroll/employees/{$employee->uuid}/components/{$uuid}")->assertOk()->assertJsonCount(1, 'data.components');

        // Read-only for viewers.
        $viewer = $this->userWithRole('staff');
        $viewer->givePermissionTo('view-payroll');
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/payroll/employees/{$employee->uuid}")->assertOk();
        $this->postJson("/api/v1/payroll/employees/{$employee->uuid}/components", ['pay_component_uuid' => $transport->uuid, 'effective_from' => '2026-01-01'])->assertForbidden();
    }
}
