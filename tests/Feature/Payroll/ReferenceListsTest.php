<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\Payroll\WorkSite;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Sites, clients and work orders, cost centres: kept by HR, used by overtime, payslips and (later) timesheets. */
class ReferenceListsTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.overtime'])->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();
        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'view-payroll', 'view-overtime']);
        Sanctum::actingAs($this->hr);
    }

    public function test_sites_are_kept_with_unique_codes_by_people_who_configure_payroll(): void
    {
        $uuid = $this->postJson('/api/v1/payroll/work-sites', ['code' => 'mv25', 'name' => 'MV25 vessel', 'is_offshore' => true])
            ->assertCreated()->assertJsonPath('data.code', 'MV25')->assertJsonPath('data.is_offshore', true)->json('data.uuid');
        $this->postJson('/api/v1/payroll/work-sites', ['code' => 'MV25', 'name' => 'Another'])->assertJsonValidationErrors('code');
        $this->putJson("/api/v1/payroll/work-sites/{$uuid}", ['code' => 'MV25', 'name' => 'FPSO MV25', 'is_offshore' => true, 'active' => true])
            ->assertOk()->assertJsonPath('data.name', 'FPSO MV25');
        $this->getJson('/api/v1/payroll/work-sites')->assertOk()->assertJsonCount(1, 'data');

        $viewer = $this->userWithRole('staff');
        $viewer->givePermissionTo('view-payroll');
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/payroll/work-sites')->assertOk();
        $this->postJson('/api/v1/payroll/work-sites', ['code' => 'TC', 'name' => 'TC'])->assertForbidden();

        Sanctum::actingAs($this->hr);
        $this->deleteJson("/api/v1/payroll/work-sites/{$uuid}")->assertOk();
        // A removed site's code can be used again.
        $this->postJson('/api/v1/payroll/work-sites', ['code' => 'MV25', 'name' => 'MV25'])->assertCreated();
    }

    public function test_clients_have_work_orders(): void
    {
        $client = $this->postJson('/api/v1/payroll/clients', ['name' => 'Tullow Oil – Ghana'])->assertCreated()->json('data.uuid');
        $order = $this->postJson('/api/v1/payroll/work-orders', [
            'number' => 'PO# 4500028551', 'client_uuid' => $client, 'description' => 'NDT inspection on IP COT', 'start_date' => '2026-09-01',
        ])->assertCreated()->assertJsonPath('data.client.name', 'Tullow Oil – Ghana')->json('data.uuid');
        $this->postJson('/api/v1/payroll/work-orders', ['number' => 'PO# 4500028551'])->assertJsonValidationErrors('number');
        $this->postJson('/api/v1/payroll/work-orders', ['number' => 'WO-2', 'start_date' => '2026-09-10', 'end_date' => '2026-09-01'])
            ->assertJsonValidationErrors('end_date');
        // Internal work: no client.
        $this->postJson('/api/v1/payroll/work-orders', ['number' => 'PRJ-7'])->assertCreated()->assertJsonPath('data.client', null);

        $this->getJson('/api/v1/payroll/clients')->assertOk()
            ->assertJsonCount(1, 'data.clients')->assertJsonCount(2, 'data.work_orders');

        // A client with work orders can't be removed.
        $this->deleteJson("/api/v1/payroll/clients/{$client}")->assertStatus(422);
        $this->deleteJson("/api/v1/payroll/work-orders/{$order}")->assertOk();
        $this->deleteJson("/api/v1/payroll/clients/{$client}")->assertOk();
    }

    public function test_an_employees_cost_centre_is_on_their_payslip_or_else_their_department(): void
    {
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        $staff = $this->userWithRole('staff')->employee;
        $staff->update(['ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $staff->id, 'effective_from' => '2026-01-01', 'basic_salary' => 3000, 'payment_method' => 'cash']);

        $ndt = $this->postJson('/api/v1/payroll/cost-centres', ['code' => 'ndt', 'name' => 'NDT'])->assertCreated()->json('data.uuid');
        $this->putJson("/api/v1/payroll/employees/{$staff->uuid}/cost-centre", ['cost_centre_uuid' => $ndt])->assertOk()
            ->assertJsonPath('data.employee.cost_centre.code', 'NDT');
        $this->getJson('/api/v1/payroll/cost-centres')->assertJsonPath('data.0.employees', 1);

        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk();
        $slip = fn () => PayRun::where('uuid', $run)->first()->payslips()->sole();
        $this->assertSame('NDT', $slip()->employee_snapshot['cost_centre']);

        $this->putJson("/api/v1/payroll/employees/{$staff->uuid}/cost-centre", ['cost_centre_uuid' => null])->assertOk()
            ->assertJsonPath('data.employee.cost_centre', null);
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk();
        $this->assertSame($staff->department->name, $slip()->employee_snapshot['cost_centre']);
    }

    public function test_overtime_locations_come_from_the_active_sites(): void
    {
        Carbon::setTestNow('2026-06-30 10:00:00');
        app(SettingService::class)->set('payroll.overtime_locations', ['Head office']);
        $staff = $this->userWithRole('staff');
        EmployeePayProfile::create(['employee_id' => $staff->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 4400, 'payment_method' => 'cash']);
        $component = PayComponent::create(['code' => 'OT', 'name' => 'Overtime', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.25, 'taxable' => true]);
        $this->postJson('/api/v1/payroll/overtime-types', ['name' => 'Any', 'pay_component_uuid' => $component->uuid, 'applies_on' => 'any'])->assertCreated();

        Sanctum::actingAs($staff);
        $request = fn (string $location) => $this->postJson('/api/v1/payroll/my-overtime', ['work_date' => '2026-06-29', 'hours' => 1, 'reason' => 'Shutdown', 'location' => $location]);

        // No sites yet: the locations setting.
        $this->getJson('/api/v1/payroll/my-overtime/options')->assertJsonPath('data.locations', ['Head office']);
        $request('Head office')->assertCreated();

        WorkSite::create(['code' => 'TC', 'name' => 'Takoradi base']);
        WorkSite::create(['code' => 'OLD', 'name' => 'Closed yard', 'active' => false]);
        $this->getJson('/api/v1/payroll/my-overtime/options')->assertJsonPath('data.locations', ['Takoradi base']);
        $request('Head office')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'location from the list'));
        $request('Takoradi base')->assertCreated();

        Carbon::setTestNow();
    }
}
