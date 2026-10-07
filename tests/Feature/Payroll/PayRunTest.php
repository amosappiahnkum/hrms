<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class PayRunTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $preparer;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();

        $this->preparer = $this->userWithRole('hr');
        $this->preparer->givePermissionTo(['prepare-payroll', 'approve-payroll', 'configure-payroll', 'view-payroll']);
        $this->approver = $this->userWithRole('staff');
        $this->approver->givePermissionTo(['approve-payroll', 'view-payroll']);
        Sanctum::actingAs($this->preparer);
    }

    private function paid(User $user, array $profile = [], array $employee = []): User
    {
        $user->employee->update($employee + ['ssnit_number' => 'C1234']);
        EmployeePayProfile::create($profile + [
            'employee_id' => $user->employee_id, 'effective_from' => '2026-01-01', 'basic_salary' => 5000,
            'payment_method' => 'bank', 'bank_name' => 'GCB', 'account_number' => '1011',
        ]);

        return $user;
    }

    public function test_a_run_is_calculated_approved_by_someone_else_and_paid(): void
    {
        $staff = $this->paid($this->userWithRole('staff'));
        $leaver = $this->paid($this->userWithRole('staff'), [], ['termination_date' => '2026-05-20']);
        $joiner = $this->paid($this->userWithRole('staff'));
        $joiner->employee->jobDetail()->updateOrCreate([], ['joined_date' => '2026-07-03']);

        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->assertCreated()
            ->assertJsonPath('data.name', 'Payroll · June 2026')->json('data.uuid');
        $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->assertStatus(422);

        // The seeded rates must be checked first.
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'confirmed'));
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk()
            ->assertJsonPath('data.status.value', 'calculated')
            ->assertJsonPath('data.totals.employees', 1) // the leaver left before June, the joiner joins after
            // 5,000 basic, no reliefs: SSNIT 275, taxable 4,725 → PAYE 779.75, net 3,945.25.
            ->assertJsonPath('data.totals.net_pay', 3945.25)
            ->assertJsonPath('data.totals.with_warnings', 0);

        $slip = $this->getJson("/api/v1/payroll/runs/{$run}/payslips")->assertOk()
            ->json('data.data.0.uuid');
        $this->getJson("/api/v1/payroll/runs/{$run}/payslips/{$slip}")->assertOk()
            ->assertJsonPath('data.figures.paye', 779.75)
            ->assertJsonPath('data.lines.0.code', 'BASIC');

        // The preparer can't approve their own run; the other approver can.
        $this->postJson("/api/v1/payroll/runs/{$run}/submit")->assertOk()->assertJsonPath('data.status.value', 'pending_approval')->assertJsonPath('data.can.decide', false);
        $approval = PayRun::where('uuid', $run)->first()->approval;
        $this->assertSame([$this->approver->id], $approval->current_approver_ids);
        $this->postJson("/api/v1/payroll/approvals/{$approval->uuid}/decide", ['decision' => 'approved'])->assertForbidden();
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertStatus(422);

        Sanctum::actingAs($this->approver);
        $this->getJson("/api/v1/payroll/runs/{$run}")->assertJsonPath('data.can.decide', true);
        $this->postJson("/api/v1/payroll/approvals/{$approval->uuid}/decide", ['decision' => 'approved', 'comment' => 'Checked'])->assertOk();
        $this->getJson("/api/v1/payroll/runs/{$run}")->assertJsonPath('data.status.value', 'approved')->assertJsonPath('data.approval.steps.0.state', 'approved');

        // Approved: final, and the rates it used are locked.
        Sanctum::actingAs($this->preparer);
        $this->deleteJson("/api/v1/payroll/runs/{$run}")->assertStatus(422);
        $set = StatutoryRateSet::first();
        $this->deleteJson("/api/v1/payroll/statutory-rates/{$set->uuid}")->assertStatus(422);
        $this->postJson("/api/v1/payroll/runs/{$run}/paid")->assertOk()->assertJsonPath('data.status.value', 'paid');
    }

    public function test_a_rejected_run_goes_back_to_be_recalculated_and_missing_rates_stop_it(): void
    {
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        $this->paid($this->userWithRole('staff'), ['currency' => 'USD', 'basic_salary' => 1000]);
        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');

        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'USD'));
        $this->postJson('/api/v1/payroll/exchange-rates', ['currency' => 'USD', 'year' => 2026, 'rate' => 15])->assertCreated();
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk()->assertJsonPath('data.totals.gross_pay', 15000);

        $this->postJson("/api/v1/payroll/runs/{$run}/submit")->assertOk();
        Sanctum::actingAs($this->approver);
        $approval = PayRun::where('uuid', $run)->first()->approval;
        $this->postJson("/api/v1/payroll/approvals/{$approval->uuid}/decide", ['decision' => 'rejected', 'comment' => 'Wrong rate'])->assertOk();

        Sanctum::actingAs($this->preparer);
        $this->getJson("/api/v1/payroll/runs/{$run}")->assertJsonPath('data.status.value', 'calculated');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk();
    }

    public function test_employees_see_their_own_payslips_once_the_run_is_paid(): void
    {
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        Setting::where('key', 'payroll.email_payslips')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        $staff = $this->paid($this->userWithRole('staff'));
        $other = $this->paid($this->userWithRole('staff'));

        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk();
        $slip = \App\Models\Payroll\Payslip::where('employee_id', $staff->employee_id)->first();
        $this->get("/api/v1/payroll/runs/{$run}/payslips/{$slip->uuid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/payroll/my-payslips')->assertOk()->assertJsonCount(0, 'data');
        $this->get("/api/v1/payroll/my-payslips/{$slip->uuid}/pdf")->assertNotFound();

        Sanctum::actingAs($this->preparer);
        $this->postJson("/api/v1/payroll/runs/{$run}/submit")->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson('/api/v1/payroll/approvals/' . PayRun::where('uuid', $run)->first()->approval->uuid . '/decide', ['decision' => 'approved'])->assertOk();
        Sanctum::actingAs($this->preparer);
        $this->postJson("/api/v1/payroll/runs/{$run}/paid")->assertOk();
        Notification::assertSentTo($staff, \App\Notifications\PayslipAvailableNotification::class);

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/payroll/my-payslips')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.net_pay', 3945.25);
        $this->get("/api/v1/payroll/my-payslips/{$slip->uuid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        $otherSlip = \App\Models\Payroll\Payslip::where('employee_id', $other->employee_id)->first();
        $this->get("/api/v1/payroll/my-payslips/{$otherSlip->uuid}/pdf")->assertNotFound();
    }

    public function test_reports_and_payment_files_come_from_an_approved_run(): void
    {
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        $gcb = $this->paid($this->userWithRole('staff'), ['bank_name' => 'GCB', 'account_name' => 'Ama Mensah', 'account_number' => '1011']);
        $this->paid($this->userWithRole('staff'), ['bank_name' => 'Ecobank', 'account_number' => '2022']);
        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk();

        $layout = $this->postJson('/api/v1/payroll/payment-file-layouts', [
            'name' => 'GCB upload', 'payment_method' => 'bank', 'bank_name' => 'gcb', 'format' => 'csv', 'delimiter' => ',', 'include_header' => true,
            'columns' => [['field' => 'account_number', 'heading' => 'ACCOUNT'], ['field' => 'account_name', 'heading' => 'NAME'], ['field' => 'amount', 'heading' => 'AMOUNT'], ['field' => 'narration', 'heading' => 'NARRATION']],
        ])->assertCreated()->json('data.uuid');

        // Before approval: the register to check, nothing for the outside world.
        $this->get("/api/v1/payroll/runs/{$run}/reports/register")->assertOk();
        $this->getJson("/api/v1/payroll/runs/{$run}/reports/ssnit")->assertStatus(422);
        $this->getJson("/api/v1/payroll/runs/{$run}/payment-files/{$layout}")->assertStatus(422);

        $this->postJson("/api/v1/payroll/runs/{$run}/submit")->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson('/api/v1/payroll/approvals/' . PayRun::where('uuid', $run)->first()->approval->uuid . '/decide', ['decision' => 'approved'])->assertOk();
        Sanctum::actingAs($this->preparer);

        $ssnit = $this->get("/api/v1/payroll/runs/{$run}/reports/ssnit")->assertOk();
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($ssnit->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
        $this->assertSame(['SSNIT number', 'Name', 'Staff ID', 'Basic salary', 'Employee', 'Employer', 'Total', 'Tier 1', 'Tier 2'], $sheet[0]);
        $this->assertEquals([5000, 275, 650, 925, 675, 250], array_slice($sheet[1], 3));

        $csv = $this->get("/api/v1/payroll/runs/{$run}/payment-files/{$layout}")->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertSame('ACCOUNT,NAME,AMOUNT,NARRATION', $lines[0]);
        $this->assertCount(2, $lines, 'only the GCB employee');
        $this->assertSame('1011,"Ama Mensah",3945.25,"Salary June 2026"', $lines[1]);
        $this->get("/api/v1/payroll/runs/{$run}/reports/journal")->assertOk();
    }

    public function test_a_run_lists_who_it_leaves_out_and_can_be_reopened_and_rerun_after_details_are_filled(): void
    {
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);
        $paid = $this->paid($this->userWithRole('staff'));
        $blank = $this->userWithRole('staff')->employee;

        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk()->assertJsonPath('data.totals.employees', 1);
        $leftOut = collect($this->getJson("/api/v1/payroll/runs/{$run}")->json('data.left_out.people'));
        $this->assertSame('No pay details', $leftOut->firstWhere('uuid', $blank->uuid)['reason']);

        // Sent for approval, then the missing details turn up: reopen, fill them in, rerun.
        $this->postJson("/api/v1/payroll/runs/{$run}/submit")->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$run}/reopen")->assertOk()->assertJsonPath('data.status.value', 'draft');
        $this->assertSame('cancelled', PayRun::where('uuid', $run)->first()->approval->status);

        // Details that start after June don't count for June, and the reason says so.
        $late = EmployeePayProfile::create(['employee_id' => $blank->id, 'effective_from' => '2026-07-01', 'basic_salary' => 4000, 'payment_method' => 'cash']);
        $this->assertStringContainsString('after this month', collect($this->getJson("/api/v1/payroll/runs/{$run}")->json('data.left_out.people'))->firstWhere('uuid', $blank->uuid)['reason']);
        $late->update(['effective_from' => '2026-06-01']);

        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk()
            ->assertJsonPath('data.totals.employees', 2)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '1 added since the last calculation'));
        $this->assertNull(collect($this->getJson("/api/v1/payroll/runs/{$run}")->json('data.left_out.people'))->firstWhere('uuid', $blank->uuid));

        // A paid run can't be reopened.
        PayRun::where('uuid', $run)->update(['status' => \App\Enums\Payroll\PayRunStatus::PAID]);
        $this->postJson("/api/v1/payroll/runs/{$run}/reopen")->assertStatus(422);
    }
}
