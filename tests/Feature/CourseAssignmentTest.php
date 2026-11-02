<?php

namespace Tests\Feature;

use App\Models\Training\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class CourseAssignmentTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccess(['training.enabled']);
        Mail::fake();

        $this->hr = $this->userWithRole('hr');
        $this->hr->givePermissionTo('manage-training');
        Sanctum::actingAs($this->hr);
        $this->actingAs($this->hr);
        $this->course = Course::create(['title' => 'Safety induction', 'passing_score' => 50]);
    }

    public function test_assignment_groups_come_back_with_names(): void
    {
        $learner = $this->userWithRole('staff')->employee;

        $this->postJson("/api/v1/training/courses/{$this->course->uuid}/assign", [
            'assignments' => [
                ['scope_type' => 'employee', 'scope_ids' => [$learner->uuid, 'missing-uuid']],
                ['scope_type' => 'department', 'scope_ids' => [$learner->department->uuid], 'due_date' => '2026-12-01'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.targets.0', [
                'value' => $learner->uuid,
                'label' => "{$learner->first_name} {$learner->last_name}",
            ])
            ->assertJsonPath('data.assignments.0.targets.1.label', 'Removed')
            ->assertJsonPath('data.assignments.1.targets.0.label', $learner->department->name);
    }

    public function test_all_groups_can_be_removed(): void
    {
        $this->postJson("/api/v1/training/courses/{$this->course->uuid}/assign", [
            'assignments' => [['scope_type' => 'all']],
        ])->assertOk();

        $this->postJson("/api/v1/training/courses/{$this->course->uuid}/assign", ['assignments' => []])
            ->assertOk()
            ->assertJsonPath('data.assignments', []);
    }
}
