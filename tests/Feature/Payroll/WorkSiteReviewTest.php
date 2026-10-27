<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\OvertimeType;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\TimesheetEntry;
use App\Models\Payroll\WorkSite;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** Sites typed in on timesheets and overtime requests, and HR keeping, merging or discarding them. */
class WorkSiteReviewTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $staff;
    private WorkSite $base;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-01 09:00:00');
        $this->setUpAccess([]);
        Setting::whereIn('key', ['features.payroll.enabled', 'features.payroll.timesheets', 'features.payroll.overtime'])->update(['value' => true]);
        $settings = app(SettingService::class);
        $settings->refreshCache();
        $settings->set('payroll.period_start_day', 21);
        $settings->set('payroll.timesheet_columns', [
            ['key' => 'normal_hours', 'label' => 'Normal', 'type' => 'hours', 'normal' => true],
            ['key' => 'ot_weekday', 'label' => 'Overtime', 'type' => 'hours', 'feeds' => 'overtime', 'day_kind' => 'weekday'],
            ['key' => 'offshore', 'label' => 'Offshore days', 'type' => 'check', 'requires_offshore' => true],
        ]);

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo(['configure-payroll', 'view-payroll']);
        $this->staff = $this->userWithRole('staff');
        $this->base = WorkSite::create(['code' => 'TC', 'name' => 'Takoradi base']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The staff member's sheet with a day at a site. */
    private function workAt(WorkSite $site, string $date = '2026-08-24', array $values = []): string
    {
        Sanctum::actingAs($this->staff);
        $uuid = $this->getJson('/api/v1/payroll/my-timesheet?year=2026&month=9')->json('data.uuid');
        $this->putJson("/api/v1/payroll/my-timesheets/{$uuid}/days/{$date}", ['entries' => [['work_site_uuid' => $site->uuid, 'hours' => 8, 'values' => $values]]]);

        return $uuid;
    }

    public function test_anyone_filling_a_timesheet_can_find_or_add_a_site(): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/v1/payroll/work-sites/search?q=tako')->assertOk()->assertJsonPath('data.0.code', 'TC');

        $new = $this->postJson('/api/v1/payroll/work-sites/quick', ['name' => 'Tema  Port'])->assertCreated()->json('data');
        $this->assertSame(['TP', 'Tema Port', false, true], [$new['code'], $new['name'], $new['is_offshore'], $new['needs_review']]);
        $this->assertSame($this->staff->id, WorkSite::where('uuid', $new['uuid'])->value('created_by'));

        // The same name again (case and spaces aside), or an existing code: that site, not a copy.
        $this->postJson('/api/v1/payroll/work-sites/quick', ['name' => 'tema port'])->assertOk()->assertJsonPath('data.uuid', $new['uuid']);
        $this->postJson('/api/v1/payroll/work-sites/quick', ['name' => 'tc'])->assertOk()->assertJsonPath('data.uuid', $this->base->uuid);
        $this->assertSame('TP2', $this->postJson('/api/v1/payroll/work-sites/quick', ['name' => 'Takoradi Port'])->assertCreated()->json('data.code'));

        // HR's list only.
        $this->getJson('/api/v1/payroll/work-sites')->assertForbidden();
    }

    public function test_a_new_site_cant_unlock_offshore_work_until_hr_says_so(): void
    {
        Sanctum::actingAs($this->staff);
        $site = WorkSite::where('uuid', $this->postJson('/api/v1/payroll/work-sites/quick', ['name' => 'Jubilee FPSO'])->json('data.uuid'))->first();
        $uuid = $this->getJson('/api/v1/payroll/my-timesheet?year=2026&month=9')->json('data.uuid');
        $this->putJson("/api/v1/payroll/my-timesheets/{$uuid}/days/2026-08-24", ['entries' => [['work_site_uuid' => $site->uuid, 'hours' => 8, 'values' => ['offshore' => true]]]])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'needs an offshore site'));

        // Kept by HR as an offshore site: reviewed, and offshore days count there.
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/payroll/work-sites')->assertOk()
            ->assertJsonPath('data.0.uuid', $site->uuid)->assertJsonPath('data.0.needs_review', true)->assertJsonPath('data.0.created_by', $this->staff->name);
        $this->putJson("/api/v1/payroll/work-sites/{$site->uuid}", ['code' => 'JUB', 'name' => 'Jubilee FPSO', 'is_offshore' => true, 'active' => true])->assertOk()
            ->assertJsonPath('data.needs_review', false);
        Sanctum::actingAs($this->staff);
        $this->putJson("/api/v1/payroll/my-timesheets/{$uuid}/days/2026-08-24", ['entries' => [['work_site_uuid' => $site->uuid, 'hours' => 8, 'values' => ['offshore' => true]]]])->assertOk();
    }

    public function test_hr_merges_a_duplicate_or_discards_an_unused_one(): void
    {
        Sanctum::actingAs($this->staff);
        $dupe = WorkSite::where('uuid', $this->postJson('/api/v1/payroll/work-sites/quick', ['name' => 'Takoradi Harbour Base'])->json('data.uuid'))->first();
        $unused = WorkSite::where('uuid', $this->postJson('/api/v1/payroll/work-sites/quick', ['name' => 'Typo site'])->json('data.uuid'))->first();
        $this->workAt($dupe);
        $request = OvertimeRequest::create([
            'employee_id' => $this->staff->employee_id, 'overtime_type_id' => OvertimeType::create([
                'name' => 'Weekday', 'applies_on' => 'weekday',
                'pay_component_id' => PayComponent::create(['code' => 'OT_WD', 'name' => 'OT', 'kind' => 'earning', 'calculation' => 'hourly_multiplier', 'rate' => 1.25])->id,
            ])->id, 'work_date' => '2026-08-25', 'hours_requested' => 2, 'approved_hours' => 2, 'status' => 'approved',
            'work_site_id' => $dupe->id, 'location' => $dupe->name,
        ]);

        Sanctum::actingAs($this->hr);
        $this->assertSame(2, collect($this->getJson('/api/v1/payroll/work-sites')->json('data'))->firstWhere('uuid', $dupe->uuid)['uses']);
        // In use: discarding is refused, merging moves everything across.
        $this->deleteJson("/api/v1/payroll/work-sites/{$dupe->uuid}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Merge it'));
        $this->postJson("/api/v1/payroll/work-sites/{$dupe->uuid}/merge", ['into_uuid' => $dupe->uuid])->assertStatus(422);
        $this->postJson("/api/v1/payroll/work-sites/{$dupe->uuid}/merge", ['into_uuid' => $this->base->uuid])->assertOk();

        $this->assertSame($this->base->id, TimesheetEntry::sole()->work_site_id);
        $this->assertSame([$this->base->id, 'Takoradi base'], [$request->fresh()->work_site_id, $request->fresh()->location]);
        $this->assertSoftDeleted($dupe);
        $this->assertSame($this->base->id, WorkSite::withTrashed()->find($dupe->id)->merged_into_id);

        $this->deleteJson("/api/v1/payroll/work-sites/{$unused->uuid}")->assertOk()->assertJsonPath('message', 'Site discarded.');
        $this->assertSoftDeleted($unused);
    }
}
