<?php

namespace Tests\Feature;

use App\Models\Config\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class RecruitmentFeatureFlagsTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccess(['recruitment.enabled']);
    }

    private function turnOff(string $flag): void
    {
        Setting::where('key', "features.{$flag}")->update(['value' => false]);
    }

    public function test_the_setting_seeder_creates_the_recruitment_flags_switched_on(): void
    {
        foreach (['enabled', 'public_portal', 'job_postings', 'candidates', 'interviews'] as $flag) {
            $this->assertTrue((bool) Setting::where('key', "features.recruitment.{$flag}")->value('value'), $flag);
        }
    }

    public function test_the_migration_removes_the_unused_talent_acquisition_flags_and_keeps_recruitment_state(): void
    {
        Setting::create(['key' => 'features.talent_acquisition.enabled', 'value' => false, 'group' => 'talent_acquisition']);
        Setting::create(['key' => 'features.talent_acquisition.feedback', 'value' => true, 'group' => 'talent_acquisition']);
        $this->turnOff('recruitment.enabled');

        (require database_path('migrations/2026_09_30_200000_consolidate_recruitment_feature_flags.php'))->up();

        $this->assertSame(0, Setting::where('key', 'like', 'features.talent_acquisition.%')->count());
        $this->assertFalse((bool) Setting::where('key', 'features.recruitment.enabled')->value('value'), 'an admin choice is kept');
    }

    public function test_public_portal_flag_closes_the_public_job_board_and_candidate_sign_up(): void
    {
        $this->getJson('/api/v1/public/jobs')->assertOk();

        $this->turnOff('recruitment.public_portal');

        $this->getJson('/api/v1/public/jobs')->assertForbidden();
        $this->postJson('/api/v1/public/candidate/login', ['email' => 'x@example.test', 'password' => 'x'])->assertForbidden();
    }

    public function test_turning_recruitment_off_closes_the_public_portal_too(): void
    {
        $this->turnOff('recruitment.enabled');

        $this->getJson('/api/v1/public/jobs')->assertForbidden();
    }

    public function test_each_hr_sub_flag_closes_only_its_own_area(): void
    {
        Sanctum::actingAs($this->userWithRole('hr'));

        $this->getJson('/api/v1/recruitment/job-openings')->assertOk();
        $this->getJson('/api/v1/recruitment/candidates')->assertOk();
        $this->getJson('/api/v1/recruitment/interviews')->assertOk();

        $this->turnOff('recruitment.candidates');
        $this->getJson('/api/v1/recruitment/candidates')->assertForbidden();
        $this->getJson('/api/v1/recruitment/job-openings')->assertOk();

        $this->turnOff('recruitment.job_postings');
        $this->getJson('/api/v1/recruitment/job-openings')->assertForbidden();

        $this->turnOff('recruitment.interviews');
        $this->getJson('/api/v1/recruitment/interviews')->assertForbidden();

        // Applications have no flag of their own: they follow the module.
        $this->getJson('/api/v1/recruitment/applications')->assertOk();
    }

    public function test_public_settings_tell_the_careers_pages_whether_applications_are_open(): void
    {
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.recruitment_portal_open', true);

        $this->turnOff('recruitment.public_portal');
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.recruitment_portal_open', false);

        Setting::where('key', 'features.recruitment.public_portal')->update(['value' => true]);
        $this->turnOff('recruitment.enabled');
        $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.recruitment_portal_open', false);
    }
}
