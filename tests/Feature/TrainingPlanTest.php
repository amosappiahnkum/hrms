<?php

namespace Tests\Feature;

use App\Models\CertificationProvider;
use App\Models\Config\Department;
use App\Models\EmployeeCertification;
use App\Models\TrainingPlan\TrainingCatalogueItem;
use App\Models\TrainingPlan\TrainingPlan;
use App\Models\TrainingPlan\TrainingPlanItem;
use App\Models\User;
use App\Notifications\TrainingPlanApprovalNotification;
use App\Notifications\TrainingPlanReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class TrainingPlanTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private User $hr;
    private User $validator;
    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAccess(['training_plan.enabled', 'certifications.enabled']);

        $this->hr = $this->userWithRole('hr');
        $this->validator = $this->userWithRole('staff');
        $this->validator->givePermissionTo('validate-training-plan');
        $this->approver = $this->userWithRole('staff');
        $this->approver->givePermissionTo('approve-training-plan');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function catalogue(array $overrides = []): TrainingCatalogueItem
    {
        return TrainingCatalogueItem::create($overrides + [
            'title' => 'Magnetic Particle Testing Level II', 'nature' => 'technical_development',
            'domain' => 'NDE', 'default_days' => 5, 'estimated_cost' => 2000, 'trainer' => 'Bultest & Co',
        ]);
    }

    private function createPlan(): TrainingPlan
    {
        Sanctum::actingAs($this->hr);
        $uuid = $this->postJson('/api/v1/training-plan/plans', ['year' => 2026])
            ->assertCreated()->json('data.uuid');

        return TrainingPlan::where('uuid', $uuid)->firstOrFail();
    }

    private function addItem(TrainingPlan $plan, array $overrides = []): TrainingPlanItem
    {
        Sanctum::actingAs($this->hr);
        $uuid = $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", $overrides + [
            'employee_uuid'                => $this->userWithRole('staff')->employee->uuid,
            'training_catalogue_item_uuid' => $this->catalogue(['title' => 'T ' . uniqid()])->uuid,
            'category'                     => 'technician_technical',
            'quarter'                      => 'Q3',
            'delivery'                     => 'external',
        ])->assertCreated()->json('data.created.0.uuid');

        return TrainingPlanItem::where('uuid', $uuid)->firstOrFail();
    }

    private function approvePlan(TrainingPlan $plan): void
    {
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/submit")->assertOk();
        Sanctum::actingAs($this->validator);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/validate")->assertOk();
        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/approve")->assertOk();
    }

    // ── Workflow ──────────────────────────────────────────────────────────────

    public function test_plan_is_prepared_validated_and_approved_by_three_people_and_items_follow(): void
    {
        Notification::fake();
        $plan = $this->createPlan();
        $item = $this->addItem($plan);

        $this->assertStringStartsWith('T ', $item->title, 'title copied from the catalogue');
        $this->assertSame('NDE', $item->domain);
        $this->assertSame(2000.0, (float) $item->cost, 'cost copied from the catalogue');
        $this->assertSame('draft', $item->approval_status->value);

        $this->approvePlan($plan);

        $plan->refresh();
        $item->refresh();
        $this->assertSame('approved', $plan->approval_status->value);
        $this->assertSame([$this->hr->id, $this->validator->id, $this->approver->id], [$plan->prepared_by, $plan->validated_by, $plan->approved_by]);
        $this->assertSame('approved', $item->approval_status->value);
        $this->assertSame($this->approver->id, $item->approved_by);

        Notification::assertSentTo($this->validator, TrainingPlanApprovalNotification::class);
        Notification::assertSentTo($this->approver, TrainingPlanApprovalNotification::class);
        Notification::assertSentTo($this->hr, TrainingPlanApprovalNotification::class);
    }

    public function test_each_step_must_be_taken_by_a_different_person(): void
    {
        $plan = $this->createPlan();
        $this->addItem($plan);
        $this->hr->givePermissionTo(['validate-training-plan', 'approve-training-plan']);

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/submit")->assertOk();
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/validate")->assertForbidden();

        $this->validator->givePermissionTo('approve-training-plan');
        Sanctum::actingAs($this->validator);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/validate")->assertOk();
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/approve")->assertForbidden();

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/approve")->assertForbidden();

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/approve")->assertOk();
    }

    public function test_an_empty_plan_cannot_be_submitted_and_a_submitted_plan_is_locked(): void
    {
        $plan = $this->createPlan();
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/submit")->assertStatus(422);

        $item = $this->addItem($plan);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/submit")->assertOk();

        $this->putJson("/api/v1/training-plan/plans/{$plan->uuid}", ['title' => 'Changed'])->assertStatus(422);
        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", ['cost' => 1])->assertStatus(422);
        $this->deleteJson("/api/v1/training-plan/items/{$item->uuid}")->assertStatus(422);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", [
            'employee_uuid' => $item->employee->uuid, 'title' => 'New', 'nature' => 'others',
            'category' => 'non_technical', 'quarter' => 'Q1', 'delivery' => 'internal',
        ])->assertStatus(422);
    }

    public function test_rejection_needs_a_comment_and_returns_the_plan_for_editing(): void
    {
        $plan = $this->createPlan();
        $this->addItem($plan);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/submit")->assertOk();

        Sanctum::actingAs($this->validator);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/reject")->assertStatus(422);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/reject", ['comment' => 'Costs too high'])->assertOk();

        $plan->refresh();
        $this->assertSame('rejected', $plan->approval_status->value);
        $this->assertSame('Costs too high', $plan->rejection_comment);

        Sanctum::actingAs($this->hr);
        $this->putJson("/api/v1/training-plan/plans/{$plan->uuid}", ['title' => 'Revised'])->assertOk();
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/submit")->assertOk();
        $this->assertNull($plan->fresh()->rejection_comment);
    }

    public function test_an_approver_cannot_reject_at_the_validation_stage(): void
    {
        $plan = $this->createPlan();
        $this->addItem($plan);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/submit")->assertOk();

        Sanctum::actingAs($this->approver);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/reject", ['comment' => 'No'])->assertForbidden();
    }

    // ── After approval: line-level approval ───────────────────────────────────



    public function test_status_changes_need_an_approved_item_and_approved_items_are_cancelled_not_deleted(): void
    {
        $plan = $this->createPlan();
        $item = $this->addItem($plan);

        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", ['status' => 'completed'])->assertStatus(422);

        $this->approvePlan($plan);
        Sanctum::actingAs($this->hr);

        $this->deleteJson("/api/v1/training-plan/items/{$item->uuid}")->assertStatus(422);
        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", ['status' => 'completed'])->assertOk();
        $this->assertSame(now()->toDateString(), $item->fresh()->completed_at->toDateString());

        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", ['status' => 'cancelled'])->assertOk();
        $this->assertNull($item->fresh()->completed_at);
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function test_dashboard_reproduces_the_analysis_sheet(): void
    {
        $plan = $this->createPlan();
        $done = $this->addItem($plan, ['cost' => 1000, 'quarter' => 'Q1']);
        $this->addItem($plan, ['cost' => 3000, 'quarter' => 'Q1', 'delivery' => 'internal', 'category' => 'non_technical']);
        $this->approvePlan($plan);

        Sanctum::actingAs($this->hr);
        $this->putJson("/api/v1/training-plan/items/{$done->uuid}", ['status' => 'completed'])->assertOk();

        $data = $this->getJson("/api/v1/training-plan/plans/{$plan->uuid}/dashboard")->assertOk()->json('data');

        $this->assertEquals(['planned' => 4000, 'estimated' => 5000, 'executed' => 1000, 'remaining' => 3000], $data['budget']);
        $q1 = collect($data['by_quarter'])->firstWhere('value', 'Q1');
        $this->assertSame(2, $q1['planned']);
        $this->assertSame(1, $q1['completed']);
        $this->assertEquals(0.5, $q1['rate']);
        $this->assertSame(1, collect($data['by_delivery'])->firstWhere('value', 'internal')['count']);
        $this->assertSame(1, collect($data['by_status'])->firstWhere('value', 'completed')['count']);
    }

    // ── Reminders ─────────────────────────────────────────────────────────────

    public function test_reminders_go_to_trainee_hod_and_hr_before_the_training_once_each(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-09-01 08:00:00');

        $plan = $this->createPlan();
        $trainee = $this->userWithRole('staff');
        $hod = $this->userWithRole('staff');
        Department::whereKey($trainee->employee->department_id)->update(['hod' => $hod->employee_id]);

        $soon = $this->addItem($plan, ['employee_uuid' => $trainee->employee->uuid]);
        $later = $this->addItem($plan);
        $this->approvePlan($plan);
        $soon->update(['planned_start_date' => '2026-09-08', 'status' => 'scheduled']);
        $later->update(['planned_start_date' => '2026-09-06']);

        Notification::fake();
        $this->artisan('training-plan:send-reminders')->assertSuccessful();

        Notification::assertSentTo($trainee, TrainingPlanReminderNotification::class, fn ($n) => $n->toArray($trainee)['recipient_type'] === 'trainee');
        Notification::assertSentTo($hod, TrainingPlanReminderNotification::class, fn ($n) => $n->toArray($hod)['recipient_type'] === 'hod');
        Notification::assertSentToTimes($this->hr, TrainingPlanReminderNotification::class, 1);
        Notification::assertNotSentTo($later->employee->userAccount, TrainingPlanReminderNotification::class);

        Carbon::setTestNow();
    }


    // ── Certificates ──────────────────────────────────────────────────────────

    private function uploadCertificate(TrainingPlanItem $item, array $overrides = [])
    {
        Storage::fake('s3');
        Sanctum::actingAs($this->hr);

        return $this->post('/api/v1/certifications', $overrides + [
            'employee_uuid'             => $item->employee->uuid,
            'title'                     => $item->title,
            'date_received'             => '2026-09-25',
            'does_not_expire'           => 1,
            'certification_provider_id' => CertificationProvider::firstOrCreate(['name' => 'LEEA'])->id,
            'file'                      => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
            'training_plan_item_uuid'   => $item->uuid,
            'mark_training_completed'   => 1,
        ], ['Accept' => 'application/json']);
    }

    public function test_a_certificate_can_be_linked_to_its_planned_training_and_complete_it(): void
    {
        $plan = $this->createPlan();
        $item = $this->addItem($plan);
        $this->approvePlan($plan);

        $this->uploadCertificate($item)->assertCreated()
            ->assertJsonPath('data.training.uuid', $item->uuid);

        $item->refresh();
        $this->assertSame('completed', $item->status->value);
        $this->assertSame('2026-09-25', $item->completed_at->toDateString());
        $this->assertSame(1, EmployeeCertification::where('training_plan_item_id', $item->id)->count());

        $this->getJson("/api/v1/training-plan/plans/{$plan->uuid}/items")
            ->assertOk()->assertJsonPath('data.0.certifications.0.title', $item->title);
    }

    public function test_a_certificate_cannot_be_linked_to_someone_elses_or_an_unapproved_training(): void
    {
        $plan = $this->createPlan();
        $item = $this->addItem($plan);

        $this->uploadCertificate($item)->assertStatus(422)->assertJsonValidationErrors('training_plan_item_uuid');

        $this->approvePlan($plan);
        $other = $this->userWithRole('staff')->employee;
        $this->uploadCertificate($item, ['employee_uuid' => $other->uuid])
            ->assertStatus(422)->assertJsonValidationErrors('training_plan_item_uuid');

        $this->assertSame(0, EmployeeCertification::count());
    }

    public function test_certification_managers_can_list_an_employees_trainings_to_link(): void
    {
        $plan = $this->createPlan();
        $item = $this->addItem($plan);
        $this->approvePlan($plan);

        $manager = $this->userWithRole('staff');
        $manager->givePermissionTo('manage-certifications');
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/training-plan/employee-items?employee_uuid=' . $item->employee->uuid)
            ->assertOk()->assertJsonPath('data.0.uuid', $item->uuid);
        $this->getJson("/api/v1/training-plan/plans/{$plan->uuid}")->assertForbidden();
    }

    // ── Access ────────────────────────────────────────────────────────────────

    public function test_staff_cannot_see_plans_and_validators_can_read_but_not_edit(): void
    {
        $plan = $this->createPlan();

        Sanctum::actingAs($this->userWithRole('staff'));
        $this->getJson('/api/v1/training-plan/plans')->assertForbidden();

        Sanctum::actingAs($this->validator);
        $this->getJson("/api/v1/training-plan/plans/{$plan->uuid}")->assertOk();
        $this->putJson("/api/v1/training-plan/plans/{$plan->uuid}", ['title' => 'x'])->assertForbidden();
    }

    // ── Catalogue import ──────────────────────────────────────────────────────

    public function test_catalogue_imports_from_the_workbook_normalising_values_and_skipping_existing(): void
    {
        $this->catalogue(['title' => 'API 510']);

        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Training Requirements (2026)');
        $sheet = $book->createSheet()->setTitle('List of Training ');
        $sheet->fromArray([
            ['Training Title ', 'Nature', 'Duration(days)', 'Domain ', 'Estimated Cost', 'Trainer', 'Training Location'],
            ['Advanced Leadership Course Certificate', 'IV -Professional Enhancement', 5, 'Admin', 100, 'Elevify', 'online'],
            ['API 510', 'III - Technical Development', 5, 'NDE', 2000, 'API', 'Onsite'],
            ['BOSIET', 'II - Compulsory/Mandatory', 3, 'HSE ', 1500, 'OPITO', 'onsite'],
            ['Mystery', 'Unknown', 1, 'X', 1, 'Y', 'Z'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'tp') . '.xlsx';
        (new Xlsx($book))->save($path);

        $this->artisan('training-plan:import-catalogue', ['file' => $path])
            ->expectsOutputToContain('Created 2 catalogue item(s); skipped 1 already present.')
            ->expectsOutputToContain('unknown nature')
            ->assertSuccessful();

        $leadership = TrainingCatalogueItem::where('title', 'Advanced Leadership Course Certificate')->firstOrFail();
        $this->assertSame('professional_enhancement', $leadership->nature->value);
        $this->assertSame('Online', $leadership->location);
        $this->assertSame('HSE', TrainingCatalogueItem::where('title', 'BOSIET')->value('domain'));

        @unlink($path);
    }

    // ── One plan per year ─────────────────────────────────────────────────────

    public function test_a_plan_is_named_after_its_year_and_each_year_has_one_plan(): void
    {
        $plan = $this->createPlan();
        $this->assertSame('2026 Annual Training Plan', $plan->title);

        $this->postJson('/api/v1/training-plan/plans', ['year' => 2026])
            ->assertStatus(422)->assertJsonValidationErrors('year');

        $plan->delete();
        $this->postJson('/api/v1/training-plan/plans', ['year' => 2026])->assertCreated();
    }

    public function test_a_plan_being_prepared_shows_its_draft_figures_on_the_dashboard(): void
    {
        $plan = $this->createPlan();
        $this->addItem($plan, ['cost' => 1500]);

        $data = $this->getJson("/api/v1/training-plan/plans/{$plan->uuid}/dashboard")->assertOk()->json('data');

        $this->assertTrue($data['draft']);
        $this->assertEquals(1500, $data['budget']['planned']);
        $this->assertSame(1, $data['totals']['trainings']);
        $this->assertSame(0, $data['pending']['count']);

        $this->approvePlan($plan);
        Sanctum::actingAs($this->hr);
        $this->assertFalse($this->getJson("/api/v1/training-plan/plans/{$plan->uuid}/dashboard")->json('data.draft'));
    }

    // ── One training, several trainees ────────────────────────────────────────

    public function test_one_training_can_be_planned_for_several_trainees_at_once(): void
    {
        $plan = $this->createPlan();
        $training = $this->catalogue(['title' => 'BOSIET']);
        $trainees = collect(range(1, 3))->map(fn () => $this->userWithRole('staff')->employee);

        Sanctum::actingAs($this->hr);
        $response = $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", [
            'employee_uuids'               => $trainees->pluck('uuid')->all(),
            'training_catalogue_item_uuid' => $training->uuid,
            'category'                     => 'technician_technical',
            'quarter'                      => 'Q2',
            'delivery'                     => 'external',
        ])->assertCreated()->assertJson(['message' => 'Training added for 3 trainees.']);

        $this->assertCount(3, $response->json('data.created'));
        $this->assertSame(3, $plan->items()->where('training_catalogue_item_id', $training->id)->count());
        $this->assertEqualsCanonicalizing($trainees->pluck('id')->all(), $plan->items()->pluck('employee_id')->all());
        $this->assertSame(2000.0, (float) $plan->items()->first()->cost, 'catalogue details copied to every line');
    }

    public function test_trainees_who_already_have_the_training_are_skipped_and_named(): void
    {
        $plan = $this->createPlan();
        $training = $this->catalogue(['title' => 'API 510']);
        $existing = $this->userWithRole('staff')->employee;
        $new = $this->userWithRole('staff')->employee;
        $payload = ['training_catalogue_item_uuid' => $training->uuid, 'category' => 'technician_technical', 'quarter' => 'Q1', 'delivery' => 'external'];

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", $payload + ['employee_uuids' => [$existing->uuid]])->assertCreated();

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", $payload + ['employee_uuids' => [$existing->uuid, $new->uuid]])
            ->assertCreated()
            ->assertJson(['message' => 'Added for 1 trainee(s); 1 already had it.'])
            ->assertJsonPath('data.skipped.0.uuid', $existing->uuid);

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", $payload + ['employee_uuids' => [$existing->uuid]])
            ->assertOk()->assertJson(['message' => 'Everyone selected already has this training in the plan.']);

        $this->assertSame(2, $plan->items()->count());
    }


    public function test_resending_the_same_catalogue_training_does_not_overwrite_or_resubmit_a_line(): void
    {
        $plan = $this->createPlan();
        $item = $this->addItem($plan);
        $this->approvePlan($plan);
        $item->catalogueItem->update(['title' => 'Renamed in catalogue', 'estimated_cost' => 9999]);

        Sanctum::actingAs($this->hr);
        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", [
            'training_catalogue_item_uuid' => $item->catalogueItem->uuid,
            'comment'                      => 'Venue confirmed',
        ])->assertOk()->assertJson(['message' => 'Training updated.']);

        $item->refresh();
        $this->assertSame('approved', $item->approval_status->value);
        $this->assertStringStartsWith('T ', $item->title);
        $this->assertSame(2000.0, (float) $item->cost);
    }

    public function test_one_person_can_prepare_validate_and_approve_when_the_organisation_allows_it(): void
    {
        \App\Models\Config\Setting::where('key', 'features.training_plan.require_different_approvers')->update(['value' => false]);

        $plan = $this->createPlan();
        $item = $this->addItem($plan);
        $this->hr->givePermissionTo(['validate-training-plan', 'approve-training-plan']);

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/submit")->assertOk();
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/validate")->assertOk();
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/approve")->assertOk();

        $plan->refresh();
        $this->assertSame('approved', $plan->approval_status->value);
        $this->assertSame([$this->hr->id, $this->hr->id, $this->hr->id], [$plan->prepared_by, $plan->validated_by, $plan->approved_by]);
        $this->assertSame('approved', $item->fresh()->approval_status->value);
    }

    // ── Revising an approved plan ─────────────────────────────────────────────

    public function test_an_approved_plan_is_locked_until_revised_and_a_revision_is_approved_again(): void
    {
        $plan = $this->createPlan();
        $first = $this->addItem($plan);
        $this->approvePlan($plan);

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", [
            'employee_uuid' => $first->employee->uuid, 'title' => 'Late', 'nature' => 'others',
            'category' => 'non_technical', 'quarter' => 'Q4', 'delivery' => 'internal',
        ])->assertStatus(422)->assertJson(['message' => 'The plan is approved. Revise it to add trainings.']);

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/revise")->assertOk();
        $this->assertSame('draft', $plan->fresh()->approval_status->value);
        $this->assertSame('approved', $first->fresh()->approval_status->value, 'approved trainings keep running');

        $added = $this->addItem($plan);
        $this->assertSame('draft', $added->approval_status->value);

        $this->approvePlan($plan);
        $this->assertSame('approved', $plan->fresh()->approval_status->value);
        $this->assertSame('approved', $added->fresh()->approval_status->value);
        $this->assertSame('approved', $first->fresh()->approval_status->value);
    }

    public function test_planned_details_change_only_in_a_revision_and_progress_never_needs_approval(): void
    {
        $plan = $this->createPlan();
        $item = $this->addItem($plan);
        $this->approvePlan($plan);

        Sanctum::actingAs($this->hr);
        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", ['cost' => 2500])
            ->assertStatus(422)->assertJson(['message' => 'The plan is approved. Revise it to change what is planned.']);

        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", [
            'planned_start_date' => '2026-08-03', 'status' => 'scheduled', 'comment' => 'Booked',
        ])->assertOk();
        $this->assertSame('approved', $item->fresh()->approval_status->value);

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/revise")->assertOk();
        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", ['cost' => 2500])
            ->assertOk()->assertJson(['message' => 'Training updated. It will be approved again with the revised plan.']);

        $item->refresh();
        $this->assertSame('draft', $item->approval_status->value);
        $this->assertSame('scheduled', $item->status->value, 'progress is kept');

        $this->approvePlan($plan);
        $this->assertSame('approved', $item->fresh()->approval_status->value);
        $this->assertSame(2500.0, (float) $item->fresh()->cost);
    }

    public function test_approved_trainings_keep_their_reminders_during_a_revision_and_new_drafts_get_none(): void
    {
        Carbon::setTestNow('2026-09-10 08:00:00');

        $plan = $this->createPlan();
        $past = $this->addItem($plan);
        $this->approvePlan($plan);
        $past->update(['planned_start_date' => '2026-09-05', 'planned_end_date' => '2026-09-09', 'status' => 'scheduled']);

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/revise")->assertOk();
        $draft = $this->addItem($plan, ['quarter' => 'Q3', 'planned_start_date' => '2026-09-17']);

        Notification::fake();
        $this->artisan('training-plan:send-reminders')->assertSuccessful();

        Notification::assertSentTo($this->hr, TrainingPlanReminderNotification::class, fn ($n) => $n->isOverdue());
        Notification::assertNotSentTo($draft->employee->userAccount, TrainingPlanReminderNotification::class);

        Carbon::setTestNow();
    }

    public function test_nobody_is_notified_until_a_revised_plan_is_submitted(): void
    {
        $plan = $this->createPlan();
        $this->addItem($plan);
        $this->approvePlan($plan);

        Notification::fake();
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/revise")->assertOk();
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", [
            'employee_uuids'               => collect(range(1, 3))->map(fn () => $this->userWithRole('staff')->employee->uuid)->all(),
            'training_catalogue_item_uuid' => $this->catalogue(['title' => 'Rigging'])->uuid,
            'category'                     => 'technician_technical',
            'quarter'                      => 'Q4',
            'delivery'                     => 'external',
        ])->assertCreated();

        Notification::assertNothingSent();

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/submit")->assertOk();
        Notification::assertSentToTimes($this->validator, TrainingPlanApprovalNotification::class, 1);
    }

    // ── Quarter and dates ─────────────────────────────────────────────────────

    public function test_while_planning_the_start_date_must_fall_in_the_chosen_quarter_and_year(): void
    {
        $plan = $this->createPlan();

        $payload = fn (array $extra) => $extra + [
            'employee_uuid' => $this->userWithRole('staff')->employee->uuid, 'title' => 'X', 'nature' => 'others',
            'category' => 'non_technical', 'delivery' => 'internal',
        ];

        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", $payload(['quarter' => 'Q4', 'planned_start_date' => '2026-03-10']))
            ->assertStatus(422)
            ->assertJsonPath('errors.planned_start_date.0', 'The start date is in Q1, but this training is planned for Q4.');

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", $payload(['quarter' => 'Q4', 'planned_start_date' => '2027-10-10']))
            ->assertStatus(422)
            ->assertJsonPath('errors.planned_start_date.0', "The date must be in 2026, the plan's year.");

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/items", $payload(['quarter' => 'Q4', 'planned_start_date' => '2026-11-02', 'planned_end_date' => '2026-11-06']))
            ->assertCreated();
    }

    public function test_after_approval_a_training_can_move_to_another_quarter_but_not_another_year(): void
    {
        $plan = $this->createPlan();
        $item = $this->addItem($plan, ['quarter' => 'Q2', 'planned_start_date' => '2026-05-04']);
        $this->approvePlan($plan);

        Sanctum::actingAs($this->hr);
        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", ['planned_start_date' => '2026-08-10', 'status' => 'postponed'])
            ->assertOk();
        $this->assertSame('Q2', $item->fresh()->quarter, 'the approved quarter stays on record');

        $this->putJson("/api/v1/training-plan/items/{$item->uuid}", ['planned_start_date' => '2027-01-15'])
            ->assertStatus(422)->assertJsonValidationErrors('planned_start_date');
    }

    // ── Self-service ──────────────────────────────────────────────────────────

    public function test_employees_see_only_their_own_approved_trainings_without_costs(): void
    {
        Notification::fake();
        $trainee = $this->userWithRole('staff');
        $plan = $this->createPlan();
        $mine = $this->addItem($plan, ['employee_uuid' => $trainee->employee->uuid, 'cost' => 900]);
        $this->addItem($plan); // someone else's

        // Nothing shows until the plan is approved.
        Sanctum::actingAs($trainee);
        $this->getJson('/api/v1/training-plan/my-items')->assertOk()->assertJsonCount(0, 'data.items');

        $this->approvePlan($plan);

        Sanctum::actingAs($trainee);
        $this->getJson('/api/v1/training-plan/my-items?year=2026')
            ->assertOk()
            ->assertJsonPath('data.years', [2026])
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.uuid', $mine->uuid)
            ->assertJsonMissingPath('data.items.0.cost')
            ->assertJsonMissingPath('data.items.0.approval');

        $this->getJson('/api/v1/training-plan/my-items?year=2025')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_employees_see_their_certificate_details_but_not_the_file(): void
    {
        $plan = $this->createPlan();
        $item = $this->addItem($plan);
        $this->approvePlan($plan);
        $cert = $this->uploadCertificate($item)->assertCreated()->json('data');
        $this->assertArrayHasKey('file_name', $cert); // HR sees the file

        Sanctum::actingAs(User::where('employee_id', $item->employee_id)->firstOrFail());

        $mine = $this->getJson('/api/v1/my/certifications')->assertOk()
            ->assertJsonPath('data.0.title', $item->title)
            ->assertJsonPath('data.0.training.uuid', $item->uuid)
            ->json('data.0');
        $this->assertArrayNotHasKey('file_name', $mine);
        $this->assertArrayNotHasKey('mime_type', $mine);

        $this->getJson("/api/v1/certifications/{$cert['id']}/download")->assertForbidden();
        $this->getJson("/api/v1/certifications/{$cert['id']}")->assertForbidden();
    }

    // ── Trainings (grouped) ───────────────────────────────────────────────────

    public function test_trainings_are_grouped_with_a_summary_of_their_trainees(): void
    {
        $plan = $this->createPlan();
        $ndt = $this->catalogue(['title' => 'NDT Level II']);
        $a = $this->userWithRole('staff')->employee;
        $b = $this->userWithRole('staff')->employee;
        $this->addItem($plan, ['training_catalogue_item_uuid' => $ndt->uuid, 'employee_uuid' => $a->uuid, 'quarter' => 'Q1']);
        $this->addItem($plan, ['training_catalogue_item_uuid' => $ndt->uuid, 'employee_uuid' => $b->uuid, 'quarter' => 'Q1']);
        $this->addItem($plan, ['training_catalogue_item_uuid' => null, 'title' => 'Fire drill', 'nature' => 'compulsory', 'quarter' => 'Q2']);

        $groups = $this->getJson("/api/v1/training-plan/plans/{$plan->uuid}/trainings")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->json('data.data');

        $this->assertSame("catalogue:{$ndt->uuid}", $groups[0]['key']);
        $this->assertSame(2, $groups[0]['trainees']);
        $this->assertSame(['Q1'], $groups[0]['quarters']);
        $this->assertSame('Bultest & Co', $groups[0]['trainer']);
        $this->assertSame('title:Fire drill', $groups[1]['key']);

        // One training's trainees
        $this->getJson("/api/v1/training-plan/plans/{$plan->uuid}/items?training=" . urlencode($groups[0]['key']))
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_trainees_can_be_added_to_an_existing_training_while_planning(): void
    {
        $plan = $this->createPlan();
        $first = $this->addItem($plan, ['planned_start_date' => '2026-08-03', 'planned_end_date' => '2026-08-07']);
        $key = 'catalogue:' . $first->catalogueItem->uuid;
        $newcomer = $this->userWithRole('staff')->employee;

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/trainings/trainees", [
            'training'       => $key,
            'employee_uuids' => [$newcomer->uuid, $first->employee->uuid],
            'category'       => 'supervisor_manager_lead',
        ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.created')
            ->assertJsonCount(1, 'data.skipped')
            ->assertJsonPath('data.created.0.quarter', 'Q3')
            ->assertJsonPath('data.created.0.planned_start_date', '2026-08-03')
            ->assertJsonPath('data.created.0.trainer', 'Bultest & Co')
            ->assertJsonPath('data.created.0.category.value', 'supervisor_manager_lead');

        $this->approvePlan($plan);
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/trainings/trainees", [
            'training' => $key, 'employee_uuids' => [$this->userWithRole('staff')->employee->uuid],
        ])->assertStatus(422);
    }

    public function test_approved_trainees_can_be_removed_during_a_revision_unless_certified(): void
    {
        $plan = $this->createPlan();
        $leaving = $this->addItem($plan);
        $certified = $this->addItem($plan);
        $this->approvePlan($plan);
        $this->uploadCertificate($certified)->assertCreated();

        Sanctum::actingAs($this->hr);
        $this->deleteJson("/api/v1/training-plan/items/{$leaving->uuid}")->assertStatus(422); // plan locked

        $this->postJson("/api/v1/training-plan/plans/{$plan->uuid}/revise")->assertOk();
        $this->deleteJson("/api/v1/training-plan/items/{$leaving->uuid}")->assertOk();
        $this->assertSoftDeleted($leaving);

        $this->deleteJson("/api/v1/training-plan/items/{$certified->uuid}")->assertStatus(422);
        $this->assertNotSoftDeleted($certified);
    }
}
