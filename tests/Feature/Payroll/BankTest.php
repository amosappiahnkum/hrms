<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\Bank;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PaymentFileLayout;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Banks are picked from a list (one name per bank); duplicates are merged. */
class BankTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'prepare-payroll', 'view-payroll']);
        Sanctum::actingAs($this->hr);
    }

    private function payDetails(User $user, Bank $bank)
    {
        return $this->putJson("/api/v1/payroll/employees/{$user->employee->uuid}/profile", [
            'mode' => 'correct', 'basic_salary' => 4000, 'payment_method' => 'bank', 'bank_uuid' => $bank->uuid, 'account_number' => '1011',
        ]);
    }

    public function test_a_bank_is_added_once_and_picked_on_pay_details(): void
    {
        $gcb = $this->postJson('/api/v1/payroll/banks', ['name' => 'GCB  Bank', 'code' => 'gcbl'])->assertCreated()
            ->assertJsonPath('data.name', 'GCB Bank')->assertJsonPath('data.code', 'GCBL')->json('data');
        // The same name or code (case and spaces aside) is the same bank.
        $this->postJson('/api/v1/payroll/banks', ['name' => 'gcb bank'])->assertOk()->assertJsonPath('data.uuid', $gcb['uuid']);
        $this->postJson('/api/v1/payroll/banks', ['name' => 'Something', 'code' => 'GCBL'])->assertOk()->assertJsonPath('data.uuid', $gcb['uuid']);
        $this->getJson('/api/v1/payroll/banks/search?q=gcb')->assertOk()->assertJsonPath('data.0.uuid', $gcb['uuid']);

        $staff = $this->userWithRole('staff');
        $this->putJson("/api/v1/payroll/employees/{$staff->employee->uuid}/profile", [
            'mode' => 'correct', 'basic_salary' => 4000, 'payment_method' => 'bank', 'bank_uuid' => (string) \Illuminate\Support\Str::uuid(), 'account_number' => '1',
        ])->assertJsonValidationErrors('bank_uuid');
        $this->payDetails($staff, Bank::where('uuid', $gcb['uuid'])->first())->assertOk()
            ->assertJsonPath('data.profile.bank.uuid', $gcb['uuid'])->assertJsonPath('data.profile.bank_name', 'GCB Bank');

        // Renaming the bank renames it on pay details.
        $this->putJson("/api/v1/payroll/banks/{$gcb['uuid']}", ['name' => 'GCB Bank PLC', 'code' => 'GCBL'])->assertOk();
        $this->assertSame('GCB Bank PLC', EmployeePayProfile::sole()->bank_name);
    }

    public function test_duplicates_are_merged_and_banks_in_use_are_not_removed(): void
    {
        $gcb = Bank::create(['name' => 'GCB Bank', 'active' => true]);
        $dupe = Bank::create(['name' => 'Ghana Commercial Bank', 'active' => true]);
        $unused = Bank::create(['name' => 'Typo Bank', 'active' => true]);
        $this->payDetails($this->userWithRole('staff'), $dupe)->assertOk();
        $layout = PaymentFileLayout::create(['name' => 'Upload', 'payment_method' => 'bank', 'bank_id' => $dupe->id, 'format' => 'csv', 'columns' => [['field' => 'account_number', 'heading' => 'Account']]]);

        $this->assertSame(2, collect($this->getJson('/api/v1/payroll/banks')->json('data'))->firstWhere('uuid', $dupe->uuid)['uses']);
        $this->deleteJson("/api/v1/payroll/banks/{$dupe->uuid}")->assertStatus(422);
        $this->putJson("/api/v1/payroll/banks/{$dupe->uuid}", ['name' => 'gcb bank'])->assertStatus(422); // that's another bank's name

        $this->postJson("/api/v1/payroll/banks/{$dupe->uuid}/merge", ['into_uuid' => $gcb->uuid])->assertOk();
        $this->assertSame([$gcb->id, 'GCB Bank'], [EmployeePayProfile::sole()->bank_id, EmployeePayProfile::sole()->bank_name]);
        $this->assertSame($gcb->id, $layout->fresh()->bank_id);
        $this->assertSoftDeleted($dupe);

        $this->deleteJson("/api/v1/payroll/banks/{$unused->uuid}")->assertOk();

        // Only payroll configurers manage the list.
        Sanctum::actingAs($this->userWithRole('staff'));
        $this->postJson("/api/v1/payroll/banks/{$gcb->uuid}/merge", ['into_uuid' => $gcb->uuid])->assertForbidden();
    }

    public function test_branches_belong_to_a_bank_and_carry_their_sort_code(): void
    {
        $umb = Bank::create(['name' => 'Universal Merchant Bank', 'code' => 'UMB', 'active' => true]);
        $gcb = Bank::create(['name' => 'GCB Bank', 'active' => true]);
        $takoradi = $this->postJson("/api/v1/payroll/banks/{$umb->uuid}/branches", ['name' => 'Takoradi', 'sort_code' => '300402'])->assertCreated()->json('data');
        // The same name or sort code at that bank is that branch; another bank can have its own Takoradi.
        $this->postJson("/api/v1/payroll/banks/{$umb->uuid}/branches", ['name' => ' takoradi '])->assertOk()->assertJsonPath('data.uuid', $takoradi['uuid']);
        $this->postJson("/api/v1/payroll/banks/{$umb->uuid}/branches", ['name' => 'X', 'sort_code' => '300402'])->assertOk()->assertJsonPath('data.uuid', $takoradi['uuid']);
        $gcbTakoradi = $this->postJson("/api/v1/payroll/banks/{$gcb->uuid}/branches", ['name' => 'Takoradi'])->assertCreated()->json('data');
        $this->getJson("/api/v1/payroll/banks/{$umb->uuid}/branches/search?q=300")->assertJsonPath('data.0.uuid', $takoradi['uuid']);

        // On pay details: a branch of the chosen bank only.
        $staff = $this->userWithRole('staff');
        $details = fn (string $branch) => $this->putJson("/api/v1/payroll/employees/{$staff->employee->uuid}/profile", [
            'mode' => 'correct', 'basic_salary' => 4000, 'payment_method' => 'bank', 'bank_uuid' => $umb->uuid, 'bank_branch_uuid' => $branch, 'account_number' => '1011',
        ]);
        $details($gcbTakoradi['uuid'])->assertJsonValidationErrors('bank_branch_uuid');
        $details($takoradi['uuid'])->assertOk()->assertJsonPath('data.profile.branch.uuid', $takoradi['uuid'])->assertJsonPath('data.profile.bank_branch', 'Takoradi');

        // Renaming renames it on pay details; a duplicate merges into it.
        $dupe = \App\Models\Payroll\BankBranch::create(['bank_id' => $umb->id, 'name' => 'Takoradi Main', 'active' => true]);
        EmployeePayProfile::query()->update(['bank_branch_id' => $dupe->id, 'bank_branch' => 'Takoradi Main']);
        $this->deleteJson("/api/v1/payroll/bank-branches/{$dupe->uuid}")->assertStatus(422);
        $this->postJson("/api/v1/payroll/bank-branches/{$dupe->uuid}/merge", ['into_uuid' => $gcbTakoradi['uuid']])->assertStatus(422); // another bank's
        $this->postJson("/api/v1/payroll/bank-branches/{$dupe->uuid}/merge", ['into_uuid' => $takoradi['uuid']])->assertOk();
        $this->putJson("/api/v1/payroll/bank-branches/{$takoradi['uuid']}", ['name' => 'Takoradi Harbour', 'sort_code' => '300402'])->assertOk();
        $this->assertSame('Takoradi Harbour', EmployeePayProfile::sole()->bank_branch);
        $this->assertSame(1, collect($this->getJson("/api/v1/payroll/banks/{$umb->uuid}/branches")->json('data'))->firstWhere('uuid', $takoradi['uuid'])['uses']);

        // Payment files can carry the bank code and the branch sort code.
        $this->assertArrayHasKey('bank_branch_sort_code', PaymentFileLayout::FIELDS);
        $this->assertArrayHasKey('bank_code', PaymentFileLayout::FIELDS);
    }
}
