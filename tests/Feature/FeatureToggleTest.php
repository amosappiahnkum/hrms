<?php

namespace Tests\Feature;

use App\Models\Config\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class FeatureToggleTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccess(['training_plan.enabled']);
        Setting::where('key', 'features.training_plan.enabled')->update(['group' => 'training']);
    }

    public function test_only_super_admins_can_list_and_toggle_features(): void
    {
        foreach (['staff', 'hr'] as $role) {
            Sanctum::actingAs($this->userWithRole($role));
            $this->getJson('/api/v1/features')->assertForbidden();
            $this->patchJson('/api/v1/features/training_plan.enabled', ['enabled' => false])->assertForbidden();
        }

        Sanctum::actingAs($this->userWithRole('super-admin'));
        $flag = collect($this->getJson('/api/v1/features')->assertOk()->json('data'))
            ->firstWhere('key', 'training_plan.enabled');

        $this->assertSame(['key' => 'training_plan.enabled', 'module' => 'training_plan', 'name' => 'enabled', 'enabled' => true], array_intersect_key($flag, array_flip(['key', 'module', 'name', 'enabled'])));
    }

    public function test_toggling_takes_effect_immediately_keeps_the_group_and_is_audited(): void
    {
        $admin = $this->userWithRole('super-admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/training-plan/plans')->assertOk();

        $this->patchJson('/api/v1/features/training_plan.enabled', ['enabled' => false])->assertOk();

        $this->getJson('/api/v1/training-plan/plans')->assertForbidden();
        $setting = Setting::where('key', 'features.training_plan.enabled')->first();
        $this->assertFalse((bool) $setting->value);
        $this->assertSame('training', $setting->group, 'toggling must not wipe the group');

        $entry = Activity::inLog('security')->where('event', 'feature_toggled')->firstOrFail();
        $this->assertSame($admin->id, (int) $entry->causer_id);
        $this->assertFalse($entry->properties['enabled']);
    }

    public function test_unknown_features_cannot_be_created_by_toggling(): void
    {
        Sanctum::actingAs($this->userWithRole('super-admin'));

        $this->patchJson('/api/v1/features/does_not.exist', ['enabled' => true])->assertNotFound();
        $this->assertFalse(Setting::where('key', 'features.does_not.exist')->exists());
    }

    public function test_training_plans_and_the_competency_matrix_are_off_until_switched_on(): void
    {
        // As a fresh install: the seeder creates both flags switched off.
        Setting::whereIn('key', ['features.training_plan.enabled', 'features.competency.enabled'])->delete();
        $this->seed(\Database\Seeders\SettingSeeder::class);
        app(\App\Services\SettingService::class)->refreshCache();

        $this->assertFalse(feature('training_plan.enabled'));
        $this->assertFalse(feature('competency.enabled'));

        Sanctum::actingAs($this->userWithRole('super-admin'));
        $this->getJson('/api/v1/training-plan/plans')->assertForbidden();
        $this->getJson('/api/v1/competency/competencies')->assertForbidden();
    }
}
