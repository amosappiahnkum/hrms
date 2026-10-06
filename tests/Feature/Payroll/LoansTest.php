<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\Loan;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\StatutoryRateSet;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class LoansTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $runApprover;
    private User $staff;
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-15 10:00:00');
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.loans'])->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        Notification::fake();
        StatutoryRateSet::query()->update(['confirmed_at' => now()]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'approve-payroll', 'view-payroll', 'manage-loans']);
        $this->runApprover = $this->userWithRole('staff');
        $this->runApprover->givePermissionTo(['approve-payroll', 'view-payroll']);
        $this->staff = $this->userWithRole('staff');
        $this->staff->employee->update(['ssnit_number' => 'C1']);
        EmployeePayProfile::create(['employee_id' => $this->staff->employee->id, 'effective_from' => '2026-01-01', 'basic_salary' => 5000, 'payment_method' => 'cash']);

        Sanctum::actingAs($this->hr);
        $this->type = $this->postJson('/api/v1/payroll/loan-types', [
            'name' => 'Salary advance', 'interest_method' => 'none', 'max_times_basic' => 2, 'max_tenor_months' => 6, 'max_deduction_percent' => 50,
        ])->assertCreated()->json('data.uuid');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_loan_is_checked_approved_paid_out_and_repaid_through_payroll(): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/payroll/my-loans/options')->assertJsonPath('data.types.0.limits.max_amount', 10000);
        $this->postJson('/api/v1/payroll/my-loans/check', ['loan_type_uuid' => $this->type, 'amount' => 12000, 'tenor_months' => 6])
            ->assertJsonPath('data.problems.0', fn ($m) => str_contains($m, 'most you can borrow'));
        // 6,000 over 2 months is 3,000 a month: more than half the take-home pay (basic 5,000 before any payslip).
        $this->postJson('/api/v1/payroll/my-loans/check', ['loan_type_uuid' => $this->type, 'amount' => 6000, 'tenor_months' => 2])
            ->assertJsonPath('data.problems.0', fn ($m) => str_contains($m, '50% of take-home pay'));
        $this->postJson('/api/v1/payroll/my-loans', ['loan_type_uuid' => $this->type, 'amount' => 6000, 'tenor_months' => 6, 'purpose' => 'Rent'])
            ->assertCreated()->assertJsonPath('data.status', 'pending');

        // HR approves 3,000 over 3 months.
        Sanctum::actingAs($this->hr);
        $approval = $this->getJson('/api/v1/payroll/approvals')->json('data.data.0');
        $this->assertSame(['amount', 'tenor_months'], array_column($approval['adjustable'], 'field'));
        $this->postJson("/api/v1/payroll/approvals/{$approval['uuid']}/decide", ['decision' => 'approved', 'adjustments' => ['amount' => 7000]])->assertStatus(422);
        $this->postJson("/api/v1/payroll/approvals/{$approval['uuid']}/decide", ['decision' => 'approved', 'adjustments' => ['amount' => 3000, 'tenor_months' => 3]])->assertOk();

        $loan = Loan::sole();
        $this->assertSame('approved', $loan->status);
        $this->postJson("/api/v1/payroll/loans/{$loan->uuid}/disburse", ['disbursed_on' => '2026-05-15', 'method' => 'bank', 'first_year' => 2026, 'first_month' => 4])->assertStatus(422);
        $this->postJson("/api/v1/payroll/loans/{$loan->uuid}/disburse", ['disbursed_on' => '2026-05-15', 'method' => 'bank', 'first_year' => 2026, 'first_month' => 6])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.balance', 3000)->assertJsonCount(3, 'data.instalments');

        // June's run deducts the first instalment: 3,945.25 net less 1,000.
        $run = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$run}/calculate")->assertOk()->assertJsonPath('data.totals.net_pay', 2945.25);
        $this->postJson("/api/v1/payroll/runs/{$run}/submit")->assertOk();
        Sanctum::actingAs($this->runApprover);
        $this->postJson('/api/v1/payroll/approvals/' . PayRun::where('uuid', $run)->first()->approval->uuid . '/decide', ['decision' => 'approved'])->assertOk();
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/payroll/runs/{$run}/paid")->assertOk();

        $this->getJson("/api/v1/payroll/loans/{$loan->uuid}")
            ->assertJsonPath('data.balance', 2000)->assertJsonPath('data.repayments.0.source', 'payroll')->assertJsonPath('data.instalments.0.status', 'paid');

        // July's is paused to the end; then the rest is settled by hand.
        $july = $loan->instalments()->where('number', 2)->first();
        $this->postJson("/api/v1/payroll/loans/{$loan->uuid}/instalments/{$july->uuid}/pause")->assertOk()
            ->assertJsonCount(4, 'data.instalments')->assertJsonPath('data.next_due', '2026-08')->assertJsonPath('data.instalments.3.period', '2026-09');
        $this->postJson("/api/v1/payroll/loans/{$loan->uuid}/repayments", ['amount' => 500, 'paid_on' => '2026-05-15'])->assertOk()->assertJsonPath('data.balance', 1500);
        $this->postJson("/api/v1/payroll/loans/{$loan->uuid}/settle", ['paid_on' => '2026-05-15'])->assertOk()
            ->assertJsonPath('data.status', 'settled')->assertJsonPath('data.balance', 0);

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/payroll/my-loans')->assertJsonPath('data.0.status', 'settled')->assertJsonCount(3, 'data.0.repayments');
    }

    public function test_limits_on_active_loans_guarantors_and_who_can_manage(): void
    {
        $this->putJson("/api/v1/payroll/loan-types/{$this->type}", [
            'name' => 'Salary advance', 'interest_method' => 'none', 'max_amount' => 2000, 'max_tenor_months' => 6, 'max_active_loans' => 1, 'requires_guarantor' => true,
        ])->assertOk();

        Sanctum::actingAs($this->staff);
        $this->postJson('/api/v1/payroll/my-loans', ['loan_type_uuid' => $this->type, 'amount' => 1000, 'tenor_months' => 2])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'guarantor'));
        $guarantor = $this->runApprover->employee->uuid;
        $first = $this->postJson('/api/v1/payroll/my-loans', ['loan_type_uuid' => $this->type, 'amount' => 1000, 'tenor_months' => 2, 'guarantor_uuid' => $guarantor])->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/payroll/my-loans', ['loan_type_uuid' => $this->type, 'amount' => 500, 'tenor_months' => 2, 'guarantor_uuid' => $guarantor])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'already have 1'));
        $this->getJson('/api/v1/payroll/loans')->assertForbidden();

        $this->postJson("/api/v1/payroll/my-loans/{$first}/cancel")->assertOk();
        $this->postJson('/api/v1/payroll/my-loans', ['loan_type_uuid' => $this->type, 'amount' => 500, 'tenor_months' => 2, 'guarantor_uuid' => $guarantor])->assertCreated();
    }

    public function test_a_loan_paid_out_on_the_payslip_and_a_leavers_balance_comes_off_final_pay(): void
    {
        $loan = Loan::create([
            'employee_id' => $this->staff->employee->id, 'loan_type_id' => \App\Models\Payroll\LoanType::where('uuid', $this->type)->value('id'),
            'amount_requested' => 3000, 'amount' => 3000, 'tenor_months' => 3, 'interest_method' => 'none', 'status' => 'approved',
        ]);
        $this->postJson("/api/v1/payroll/loans/{$loan->uuid}/disburse", ['disbursed_on' => '2026-06-10', 'method' => 'payroll', 'first_year' => 2026, 'first_month' => 7])->assertOk();

        // June: paid out (not taxable), nothing repaid yet.
        $june = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$june}/calculate")->assertOk()->assertJsonPath('data.totals.net_pay', 6945.25);
        $this->deleteJson("/api/v1/payroll/runs/{$june}")->assertOk();
        $this->assertNull($loan->fresh()->disbursement_run_id);

        // Leaving in July: all three instalments come off the final pay.
        $this->staff->employee->update(['termination_date' => '2026-07-31']);
        $july = $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 7])->json('data.uuid');
        $this->postJson("/api/v1/payroll/runs/{$july}/calculate")->assertOk();
        $this->getJson("/api/v1/payroll/runs/{$july}/inputs")->assertJsonFragment(['amount' => 3000.0]);
    }
}
