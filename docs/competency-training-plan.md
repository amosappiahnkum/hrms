# Competency Matrix & Training Plan — Implementation Plan

Goal: bring the Competency Matrix and Training Plan modules in line with **AI-IMS-SOP-HR-003 Competency
Management Procedure** and the competence clause (7.2) of ISO 9001 / 14001 / 45001, and make them work as one
loop:

```
Role requirements ─► Assess ─► Gap ─► Development action ─┬─► (training) Training plan ─► Delivered ─► Evaluated ─┐
        ▲                                                  └─► (coaching, OJT, …) ─────────────────────► Done ───────┤
        │                                                                                                            ▼
        └──────────── Authorization ◄── Competent (re-rated ≥ required, certs valid) ◄── Effectiveness check ◄──────┘
```

Work through the steps in order; each one can ship on its own. Tick a step when it is merged.

- Backend: `hrms-be` (this repo)
- Frontend: `/Users/israelnkum/WebstormProjects/ttu-hrms-frontend` (paths below are relative to its `src/`)
- Pending input: **AI-IMS-SOP-HR-004 Training Management** is referenced by HR-003 but not in the repo. Check Phase 3
  against it before starting that phase.

---

## Conventions (apply to every step)

- **Models**: extend `AppModel`; integer `id` + `uuid` (routes and lookups) + soft deletes.
- **Migrations**: one migration per step, named `2026_MM_DD_HHMMSS_<what>.php`. Never edit a migration that has
  run in production; add a new one.
- **Permissions**: added in a migration, using the pattern in
  `database/migrations/2026_10_01_100001_add_competency_permissions.php`.
- **Settings and feature flags**: added to `database/seeders/SettingSeeder.php` (deploy runs it), not in
  migrations. New sub-features get a flag under `features.competency.*` or `features.training_plan.*`.
- **Errors**: messages users should see go in `UserFacingException`; everything else is handled by
  `ApiResponse::fromException`, which never leaks internals.
- **Audit**: data-changing requests are logged by `RecordRequestActivity`; models that hold records
  (authorizations, evaluations) also log their changes.
- **Frontend**: API calls go in the module's RTK Query service file
  (`apps/employee-management/services/competency.service.ts`, `apps/training/services/training-plan.service.ts`);
  pages use Ant Design and the existing `StatCard`/tab layout.
- **Tests**: every step adds a feature test (`tests/Feature/CompetencyTest.php`, `TrainingPlanTest.php`, or a new
  file per area).

---

## Where we are (baseline)

| Area | In place |
|---|---|
| Competency | Library (8 groups), role requirements, assessments with ratings 0–4 and text evidence, gaps, development actions, matrix / gaps / summary endpoints, self-service "My competencies", leaders assess their team, workbook import (`competency:import-matrix`) |
| Training plan | Catalogue + domains, yearly plan, collection window for HODs, multi-level approval + sign-off, item progress (status, dates, completion), certificates linked to items, reminders |
| Link | `competency_development_actions.training_plan_item_id`; frontend `PlanTrainingForAction` (`apps/employee-management/competency/action-modal.tsx`) adds a plan line when a training action is created; plan line has `source_of_need = competency_gap_analysis` |
| Certifications | `employee_certifications` (free-text title, provider, expiry, file) + expiry reminders |
| LMS | Courses, enrolments, quizzes (`app/Models/Training`), not connected to the plan |

---

## Phase 0 — Quick wins

### 0.1 Development methods from the procedure (SOP 5.3.5, 5.3.6) — [x]
- **Backend**: add to `App\Enums\Competency\DevelopmentMethod`: `SEMINAR`, `PROFESSIONAL_MEMBERSHIP`,
  `PROJECT`, `OBSERVATION` (supervised practical activity), `KNOWLEDGE_SHARING`, `TEMPORARY_ASSIGNMENT`
  (split from `ASSIGNMENT`, which stays as "cross-functional"). Give each an `effectivenessCheck()` text from the
  procedure's table. No migration (string column).
- **Frontend**: nothing; the options come from `/competency/options`.
- **Done when**: every method in SOP 5.3.5 can be chosen and shows its effectiveness check.

### 0.2 Link a training plan line when an action is created — [x]
- **Backend**: `DevelopmentActionController::validated()` currently prohibits `training_plan_item_uuid` on create;
  allow it (same employee check via `trainingFor()`).
- **Backend**: when a plan line is linked, replace an empty or generic ("Competency Matrix") `supporting_record`
  with the assessment reference (`Competency Matrix – assessment of <date>`). Lines already approved are left
  alone (it's a planned field; changing it would need re-approval).
- **Frontend**: `action-modal.tsx`: a new training action is saved only after its plan line exists, together with
  the link, in one request. Closing the plan form, or no plan being open, still saves the action unlinked.
  `PlanTrainingForAction` now takes an `onLine` callback, so the drawer reuses it to link existing actions.
- **Done when**: an action linked to a plan line can't be left without the link after a failed second request.

---

## Phase 1 — Closed loop (audit essentials)

### 1.1 Catalogue ↔ competency mapping — [x]
Which courses develop which competencies, and to what level. Enables suggestions and needs grouped by course.
- **Migration** `create_training_catalogue_competencies_table`:
  `id, uuid, training_catalogue_item_id (fk cascade), competency_id (fk cascade), target_level tinyint, timestamps, softDeletes`,
  unique (`training_catalogue_item_id`, `competency_id`).
- **Backend**: `TrainingCatalogueItem::competencies()` (belongsToMany with `target_level`);
  `Competency::catalogueItems()`; accept `competencies: [{competency_uuid, target_level}]` in
  `TrainingCatalogueController` store/update; return them in `TrainingCatalogueItemResource`.
  New endpoint `GET /competency/competencies/{competency}/courses` (suggested courses).
- **Frontend**:
  - `apps/training/training-plans/catalogue-form-modal.tsx`: "Develops competencies" multi-select with a level per row.
  - `apps/training/training-plans/training-catalogue.tsx`: competencies column.
  - `apps/employee-management/competency/action-modal.tsx`: show suggested courses for the gap's competency;
    picking one pre-fills the plan line.
- **Done when**: a gap shows matching catalogue courses, and the plan line created from it uses that course.
- **As built**: links are a model (`TrainingCatalogueCompetency`, uuid + soft deletes, revived on re-save) rather
  than a bare pivot; relations are `competencyLinks()` / `courseLinks()`. Suggestions return `[]` while training
  plans are off. People with `prepare-training-plan` can read the competency library (`/competency/options`,
  `/competency/competencies`) through `CompetencyAccess:library`, but not the matrix or gaps. Frontend: new
  `training/training-plans/competencies-field.tsx`; the field and the "Develops" column show only when the
  competency module is on. The drawer's "plan training" for an existing action doesn't pre-select a course yet.

### 1.2 Training needs queue (hand-over to the training team) — [x]
Open training/certification actions that are not yet in a plan, for the people preparing the plan.
- **Migration**: none (uses existing `competency_development_actions`).
- **Backend**: `TrainingNeedsController` under `routes/v1/training-plan.php`, permission `prepare-training-plan`:
  - `GET /training-plan/needs`: open actions with method `training` or `certification` and no
    `training_plan_item_id`; filters: department, competency, course; `group_by=competency|course|department`
    returns counts and employees per group.
  - `POST /training-plan/plans/{plan}/needs`: `{action_uuids[], catalogue_item_uuid?, quarter, delivery, …}` creates
    one plan line per employee (`source_of_need = competency_gap_analysis`, supporting record = assessment ref),
    links each action, and sets it `in_progress`. Rejects when the plan isn't open for changes (same rule as
    `TrainingPlanItemController::store`).
- **Frontend**:
  - New tab "Training needs" in `apps/training/training-plans/training-plan-page.tsx` (`training-needs.tsx`):
    grouped list → select → "Add to plan" modal (reuse fields from `plan-item-modal.tsx`).
  - `plan-dashboard.tsx`: card "Gap-driven needs not yet planned".
  - Competency `competency-page.tsx`: the "Gaps without a plan" stat links to the gaps tab filtered to unplanned.
- **Done when**: the training team can plan all of a department's gap-driven training without retyping, and the
  competency side shows each action's plan line.
- **As built**: the queue is `TrainingNeedsController@index`; bulk planning is `TrainingPlanItemController@planNeeds`
  (it reuses that controller's validation, catalogue defaults, date checks and duplicate skipping). An employee who
  already has the training in the plan is linked to that line rather than skipped. A selection that went stale returns
  409. `DevelopmentAction::awaitingTraining()` defines a need; `recordTrainingSource()` moved onto the model. Each need
  carries the suggested course (highest target level). Gaps endpoint gained `unplanned=1`; the "Gaps without a plan"
  stat links to it. Frontend: `training-plans/training-needs.tsx` (tab shown to plan preparers when the competency
  module is on) and an "N needs not in a plan" alert on the plan overview. Queue is capped at 1000 rows (`truncated`).

### 1.3 Training record: attendance and actuals — [x]
The plan records intent; this records what happened (evidence for audits).
- **Migration** `add_actuals_to_training_plan_items_table`:
  `actual_start_date, actual_end_date (date null), hours (decimal 5,1 null), attended (bool null),
  actual_cost (decimal 12,2 null), provider (string null), score (decimal 5,2 null), passed (bool null)`.
  Add them to `TrainingPlanItem::PROGRESS_FIELDS` (no re-approval).
- **Backend**: validation in `TrainingPlanItemController::update`; `completed` requires `attended = true`;
  `failed` requires a score or comment.
- **Frontend**: `apps/training/training-plans/progress-modal.tsx`: "Attendance & results" section;
  `training-trainees-drawer.tsx`: bulk mark attendance for a training's trainees.
- **Done when**: every completed line has dates, hours and attendance; cost actuals feed 4.2.
- **As built**: the record fields are `TrainingPlanItem::ACTUAL_FIELDS` (part of `PROGRESS_FIELDS`). Rules
  (`withRecordRules`): recorded by HR on approved lines only; completing sets `attended = true` (explicit "not attended"
  + completed is refused) and copies planned dates into empty actual dates; failing needs a score or comment; actual end
  ≥ start. Hours aren't forced by the API; the progress form requires them on completion and suggests days × 8.
  Bulk: `POST /training-plan/plans/{plan}/items/progress` (all-or-nothing). Dashboard "Executed" uses `actual_cost`
  where recorded and `totals.hours` was added. Frontend: shared `record-fields.tsx`, `record-session-modal.tsx`, row
  selection + "Record session" in the trainees drawer. `provider` is free text until the provider register (3.2).

### 1.4 Training evaluation (reaction, learning, application) — [x]
- **Migration** `create_training_evaluations_table`:
  `id, uuid, training_plan_item_id (fk cascade), type enum-string (participant_feedback | supervisor_review),
  evaluator_id (users, null on delete), due_on date null, submitted_at timestamp null, rating tinyint null (1–5),
  applied_on_job bool null, answers json null, comment text null, timestamps, softDeletes`.
- **Settings** (`SettingSeeder`): `training_plan.supervisor_review_after_days` (default 90),
  `features.training_plan.evaluations` (default true).
- **Backend**:
  - On item `completed`: create `participant_feedback` (employee, due in 7 days) and `supervisor_review`
    (supervisor, due after the setting). Notification + reminder via `SendTrainingPlanReminders`.
  - `GET /training-plan/my-evaluations`, `PUT /training-plan/evaluations/{evaluation}`.
- **Frontend**:
  - Self-service `apps/self-service/training/planned-trainings.tsx`: "Give feedback" on completed trainings.
  - Self-service team view `team-training-needs.tsx`: "Reviews due" list for supervisors.
  - `progress-modal.tsx` / trainees drawer: show evaluation status.
- **Done when**: each completed training gets feedback and a supervisor review, and a review that says the
  training wasn't applied flags the linked action (1.5).
- **As built**: `TrainingEvaluation` + `EvaluationType` (questions defined in the enum, sent to the frontend).
  `TrainingPlanItem::booted()` schedules on completion and withdraws unanswered ones when the status is corrected,
  so single and bulk updates both trigger it (`TrainingEvaluationService`). Reviewer: the employee's supervisor, else
  their HOD; nobody → `evaluator_id` null and HR answers. Feedback is notified at once; a review is notified on its due
  date by `training-plan:send-reminders`, which also sends one reminder 7 days past due (`notified_at`, `reminded_at`).
  A review opens on its due date; "not applied" requires a comment. Settings: `features.training_plan.evaluations`,
  `training_plan.feedback_due_days` (7), `training_plan.supervisor_review_after_days` (90). Notification type
  `training_evaluation`. Frontend: `self-service/training/pending-evaluations.tsx` on "Planned training" and the team
  training page; evaluation status per trainee in the HR trainees drawer. **Left for 1.5**: a "not applied" review
  doesn't touch the linked development action yet.

### 1.5 Effectiveness check and closing the gap — [x]
SOP 5.3.6: development is effective only when competence is re-verified.
- **Migration** `add_effectiveness_to_competency_development_actions_table`:
  `effectiveness_result string null (effective | partially | not_effective), evaluated_by (users) null,
  evaluated_on date null, verified_level tinyint null, competency_rating_id (fk null) — the rating that
  verified it`.
  New status `awaiting_evaluation` in `DevelopmentStatus` (open).
- **Backend**:
  - Linked plan line → `completed`: action moves to `awaiting_evaluation` and the assessor is notified.
  - `POST /competency/actions/{action}/evaluate`: `{result, verified_level, evidence?}` re-rates that single
    competency (creates a completed "spot" assessment carrying the employee's other current ratings forward, so
    the latest assessment stays complete). `verified_level >= required` → action `done`, gap closes; otherwise
    action stays open with the result recorded.
  - Linked plan line → `failed`/`cancelled`: action back to `planned` with a note.
- **Frontend**: `apps/employee-management/competency/profile-view.tsx`: "Awaiting evaluation" section with an
  "Evaluate" modal (new `evaluate-modal.tsx`) showing the method's effectiveness check text;
  `gaps-tab.tsx`: status chip for awaiting evaluation.
- **Done when**: no gap closes without a re-rating, and every finished action records how its effectiveness
  was verified.
- **As built**: `EffectivenessService` (`trainingEnded()` from `TrainingPlanItem::booted()`, `evaluate()`),
  `EffectivenessResult` enum, `DevelopmentStatus::AWAITING_EVALUATION` + `openCases()`/`openValues()`. Evidence is
  required. "Effective" is refused below the required level. The spot assessment keeps the previous
  `next_review_on`. Assessor notified = action creator if they can still assess, else supervisor/HOD
  (`competency_evaluation` notification type, new "Competency Matrix" group). Failed/cancelled training → action back to
  `planned`, unlinked (returns to the needs queue), note appended to `outcome`. `PUT /actions` no longer accepts `done`
  or `awaiting_evaluation`, and finished actions can't be edited. The supervisor review (1.4) is shown in the evaluate
  modal. **Deviation:** an evaluated action is always finished (`done`) with its result; if the level still falls
  short the gap stays open and shows as "without a plan" for the next action. Keeping the action open would leave it
  linked to a completed training and stranded outside the needs queue.

### 1.6 Evidence attachments — [x]
- **Migration** `create_competency_evidence_files_table`: polymorphic
  `id, uuid, evidenceable_type, evidenceable_id, file_path, file_name, file_size, mime_type, uploaded_by, timestamps, softDeletes`
  (attached to `CompetencyRating` and `DevelopmentAction`).
- **Backend**: upload via `MinioUploadService`; `POST/DELETE /competency/evidence`; signed download URL; size and
  type limits; only people who can assess the employee can upload; viewers can download.
- **Frontend**: `assessment-modal.tsx` (per rating) and `evaluate-modal.tsx`: upload list; `profile-view.tsx`:
  paperclip with file list.
- **Done when**: any rating or effectiveness check can carry files.
- **As built**: `CompetencyEvidenceFile` (morph `evidenceable`) + `CompetencyEvidenceController`. Files are stored
  **private** (`MinioUploadService::upload()` gained a `$visibility` argument, default unchanged) and served through
  5-minute temporary URLs. Limits: pdf/jpg/png/doc/docx/xls/xlsx, 20 MB, 10 per rating/action. Upload/remove only on
  draft ratings and open actions, by people who can assess; download by anyone who can see the employee, the employee
  included (route outside `CompetencyAccess`). Removing soft-deletes the row and keeps the stored file; new assessments
  and spot checks copy file references forward (`copyTo()`), so evidence stays on the current record.

### 1.7 Certifications required per role — [x]
SOP 5.1, 5.3.3: "required qualification/certification".
- **Migrations**:
  - `create_certification_types_table`: `id, uuid, name, validity_months null, competency_id null (fk)`
    (e.g. "ASNT UT Level 2", "BOSIET").
  - `add_certification_type_id_to_employee_certifications_table` (nullable fk; existing rows stay free text).
  - `create_position_certification_requirements_table`: `id, uuid, position_id, certification_type_id, mandatory bool, timestamps, softDeletes`.
- **Backend**: CRUD for types (permission `manage-competencies`); position requirements saved with
  `PositionCompetencyController::sync`; profile/matrix add `certifications: [{type, status: valid|expiring|expired|missing}]`;
  a missing or expired mandatory certification counts as a gap and blocks authorization (1.8);
  `SendCertificationExpiryReminders` also creates a training need (`certification_renewal`) 90 days before expiry.
- **Frontend**: `requirements-tab.tsx`: "Required certifications" per position;
  `certifications/certification-form.tsx`: certification type select; `profile-view.tsx` and `matrix-tab.tsx`:
  certificate status column.
- **Done when**: the matrix shows certificate status per required certification, and expiry produces a need.
- **As built**: starts with **no certificate types**; HR adds them (Competency → Certificate types; competency or
  certificate managers). Tables `certification_types`, `position_certifications` (mandatory or recommended),
  `employee_certifications.certification_type_id`. Status rules in `CertificationStatusService` (valid / expiring ≤ 90 days /
  expired / missing; best certificate of the type wins); only mandatory missing/expired count as gaps
  (`summary.certification_gaps`). Positions: `PUT /competency/positions/{position}/certifications`. Renewal needs are
  created by `certifications:send-expiry-reminders` only for types linked to a competency (an action needs one): a
  "certification" development action "Renew X (expires …)", skipped when renewed or already open. Certificate managers can
  read the competency library (`CompetencyAccess:library`) to type certificates.

### 1.8 Authorization register — [x]
SOP definitions, 4.2, 4.4, 5.3.3 "authorization status".
- **Migrations**:
  - `create_authorization_activities_table`: `id, uuid, name, description, position_id null, timestamps, softDeletes`
    (e.g. "Sign UT inspection reports", "Examine lifting accessories (LOLER)").
  - `create_authorization_activity_requirements_table`: `authorization_activity_id, competency_id null, min_level null, certification_type_id null`.
  - `create_employee_authorizations_table`: `id, uuid, employee_id, authorization_activity_id, status (recommended | authorized | suspended | revoked | expired),
    recommended_by, recommended_at, authorized_by, authorized_at, valid_until date null, reason text null, timestamps, softDeletes`.
- **Permissions**: `recommend-authorizations` (HODs/supervisors via team access), `grant-authorizations` (management/HR).
- **Backend**: eligibility check (all requirement levels met in the latest assessment + valid certificates);
  supervisor recommends → granter authorizes; automatic `suspended` when a required certificate expires or a
  re-rating drops below `min_level`; expiry reminders; `GET /competency/authorizations` register.
- **Frontend**: new tab "Authorizations" in `competency-page.tsx` (register + activity setup);
  `profile-view.tsx`: authorizations block; self-service `my-competencies.tsx`: "What I am authorized to do".
- **Done when**: the register shows who may perform each activity, on whose authority, and until when, and it
  updates itself when competence or certificates lapse.
- **As built**: starts with **no activities**. Only `grant-authorizations` was created (to `hr`); recommending needs no
  permission: anyone who may assess the employee (`canAssess`) recommends. Statuses add `declined` (a rejected
  recommendation). `AuthorizationService`: eligibility (`unmet()` in words), recommend (or grant at once for granters),
  grant/reinstate (eligibility re-checked; `valid_until` defaults from the activity's `validity_months`), decline, revoke,
  `recheck()` (suspends, run after an assessment is completed and after an effectiveness check), `expire()`. Daily
  `competency:check-authorizations` (08:15) expires by date, flags 30 days before once, and suspends on lapsed certificates.
  Notification type `competency_authorization`. Frontend: Competency → Authorizations (register; HR also "Activities"),
  "Certificates" and "Authorizations" blocks + "Recommend" on the employee profile (`recommend-modal.tsx` lists every
  activity with what's missing), certificate column on the matrix, certificate type on the certificate form.

### 1.9 Employee development record — [x]
One page per employee: gaps, actions, trainings (with evaluation), certificates, authorizations.
- **Backend**: extend `CompetencyService::profile()`; add an Excel/PDF export of it.
- **Frontend**: `profile-view.tsx` reorganised into sections; self-service `my-competencies.tsx` shows the same
  (read-only).
- **Done when**: one view answers "is this person competent and authorized, and what is being done about the gaps".
- **As built**: `profile()` gained `readiness` (meets every requirement? open actions, gaps without a plan, activities
  authorized) and `trainings` (approved plan lines, newest first, max 30: record, competencies they were for, feedback
  and review). PDF: `GET /competency/employees/{employee}/record` (anyone who may see the employee) and
  `GET /competency/mine/record` (self-service), view `competency/record.blade.php`, letterhead from the new
  `App\Helpers\PdfLetterhead` (same settings and logo as the appraisal PDF). Frontend: readiness banner and Training section
  in `profile-view.tsx`; "Download record" in the employee drawer and on My competencies. Excel is left to Phase 4.

---

## Phase 2 — Risk and continuity

### 2.1 Critical role assessment — [ ]
SOP 5.4 (a): 8 criteria scored 1/3/5, total 8–40.
- **Migration** `create_position_criticality_assessments_table`: `id, uuid, position_id, scores json (8 keys),
  total tinyint, classification (low | moderate | key | critical), assessed_by, assessed_on, approved_by null,
  approved_at null, next_review_on null, timestamps, softDeletes`. Add `criticality` (string null) to `positions`
  (the latest approved classification).
- **Backend**: classification from total (32–40 critical, 24–31 key, 16–23 moderate, 8–15 low) and the required
  action text; approval by `approve-critical-roles` (management, SOP 4.5).
- **Frontend**: `requirements-tab.tsx`: criticality badge + "Assess criticality" modal with the 8 questions;
  `matrix-tab.tsx` and `gaps-tab.tsx`: filter and sort by criticality.
- **Done when**: every position has an approved classification, and gaps in critical roles sort first.

### 2.2 Succession planning — [ ]
SOP 5.4 (b–e), 6.
- **Migration** `create_succession_candidates_table`: `id, uuid, position_id, employee_id, readiness
  (ready_now | 1_2_years | 3_plus_years), notes, reviewed_on, timestamps, softDeletes`.
  Add `purpose` (string null: `gap | succession | career`) to `competency_development_actions`.
- **Backend**: candidates for key/critical positions; readiness = their gaps against the *target* position's
  requirements; development actions with `purpose = succession` (training needs flow as `succession_plan`).
- **Frontend**: new tab "Succession" in `competency-page.tsx`: critical roles, bench strength, candidates and
  their gap to the role.
- **Done when**: every critical role shows its successors and how ready they are.

### 2.3 Reassessment triggers — [ ]
SOP 4.2, 5.3.3: role change, incidents, process/equipment/legal changes.
- **Migration** `create_competency_reassessment_requests_table`: `id, uuid, employee_id, reason
  (role_change | incident | nonconformity | audit | process_change | equipment_change | legal_change | client_requirement | other),
  reference string null, competency_ids json null, requested_by, due_on, completed_assessment_id null, timestamps, softDeletes`.
- **Backend**: a `JobDetail` observer creates a request when the position changes; manual requests from
  HR/IMS/supervisors; notification to the assessor; overdue requests show in the summary.
- **Frontend**: "Request reassessment" on `employee-competency-drawer.tsx`; "Reassessments due" stat on
  `competency-page.tsx`.
- **Done when**: a change of position, or an incident, leads to a tracked reassessment.

### 2.4 Gap sources, self-assessment and sign-off — [ ]
SOP 4.3, 5.3.4.
- **Migrations**:
  - `add_review_fields_to_competency_assessments_table`: `type (supervisor | self)`, `self_assessment_id null`,
    `acknowledged_at null`, `employee_comment null`.
  - `add_source_to_competency_development_actions_table`: `source (assessment | appraisal | incident | nonconformity | audit | client | legal), source_reference null`.
- **Backend**: an employee can fill in a self-assessment (self-service) before the supervisor's; the supervisor sees
  both side by side; the employee acknowledges a completed assessment.
- **Frontend**: self-service `my-competencies.tsx`: "Self-assess" + "Acknowledge"; `assessment-modal.tsx`: show
  the self rating next to each competency.
- **Done when**: assessments record both views and the employee's acknowledgement.

### 2.5 Contractors and external personnel — [ ]
SOP 5.3.2.
- **Decision needed**: put external personnel in `employees` with an `employment_type = contractor` flag (so the
  matrix, certificates and authorizations work unchanged), or keep a separate light register. Recommended: the flag.
- **Migration**: `add_is_external_to_employees_table` (or the register table), plus `verified_by` and `verified_on`
  on their latest assessment.
- **Backend/Frontend**: a matrix filter for external personnel; a "verified before work" check on authorization.
- **Done when**: no external person is authorized without a recorded competence verification.

### 2.6 The matrix as a controlled record — [ ]
SOP 4.5, 5.3.3.
- **Migration** `create_competency_requirement_revisions_table`: `id, uuid, position_id, revision int,
  requirements json (snapshot), change_note, prepared_by, approved_by null, approved_at null, timestamps`.
  Add `review_due_on` to `positions`.
- **Backend**: `PositionCompetencyController::sync` saves a pending revision; requirements take effect when
  approved (approval required for key/critical positions, optional otherwise); review reminders.
- **Frontend**: `requirements-tab.tsx`: revision history, "pending approval" banner, approve/reject.
- **Done when**: every change to a role's requirements is versioned and, for critical roles, approved.

---

## Phase 3 — Training operations (check against SOP-HR-004 first)

### 3.1 Ad-hoc training requests — [ ]
- **Migration** `create_training_requests_table`: `employee_id, title, catalogue_item_id null, reason, source_of_need,
  development_action_id null, cost, requested_by, status (pending | approved | rejected), decided_by, decided_at`.
- **Backend**: approval adds the line to the current plan as an approved change (or queues it for the plan's next
  revision, per SOP-HR-004).
- **Frontend**: self-service "Request training"; training team inbox in `training-plan-page.tsx`.

### 3.2 Training providers register — [ ]
ISO 9001 8.4.
- **Decision**: extend `certification_providers` into a general `providers` table (`type` training/certification)
  or add `training_providers`. Recommended: extend.
- **Migration**: approval status, evaluation date, rating; `provider_id` on catalogue items and plan items.
- **Frontend**: Providers page under Training; provider select in `catalogue-form-modal.tsx` and `trainer-select.tsx`.

### 3.3 Internal trainer competence — [ ]
- Internal trainers must hold an authorization (1.8) for the training they deliver; `trainer-select.tsx` lists
  only authorized employees for internal delivery.

### 3.4 Mandatory and refresher training — [ ]
- **Migration**: `refresh_months` and `mandatory_for` (positions, json or a pivot table) on `training_catalogue_items`.
- **Backend**: a scheduled job creates `legal_regulatory`/`certification_renewal` needs (into the 1.2 queue) for
  everyone due within the next plan year.
- **Frontend**: `catalogue-form-modal.tsx`: refresher interval and mandatory positions.

### 3.5 Training calendar and nominations — [ ]
- Calendar view of planned and scheduled trainings (`plan-trainings.tsx` toggle); nomination notifications to
  trainees and their supervisors; trainees confirm attendance.

### 3.6 Count LMS course completions — [ ]
- **Migration**: `course_id` (nullable) on `training_catalogue_items`.
- **Backend**: `CourseEnrollment` completion marks the employee's linked plan line `completed` with the quiz score
  (1.3) and triggers 1.4 and 1.5.

---

## Phase 4 — Reports and exports

All endpoints filter by department, position and period; each has Excel export (Maatwebsite, `app/Exports/`).

| # | Report | Endpoint | Page |
|---|---|---|---|
| 4.1 | Training needs analysis: gaps by competency/course, employees, average shortfall | `/competency/reports/tna` | new "Reports" tab in `competency-page.tsx` |
| 4.2 | Plan compliance: planned vs delivered, completion by quarter/department, hours per employee, spend vs budget | `/training-plan/plans/{plan}/reports/compliance` | `plan-dashboard.tsx` |
| 4.3 | Effectiveness: share of training whose re-rating met the target; before/after levels | `/competency/reports/effectiveness` | competency Reports tab |
| 4.4 | Heatmap: department × competency group gap rate | `/competency/reports/heatmap` | competency Reports tab |
| 4.5 | Role readiness and critical-role coverage / bench strength | `/competency/reports/readiness` | competency Reports tab |
| 4.6 | Expiring authorizations and certificates; overdue reviews and reassessments | `/competency/reports/expiring` | competency Reports tab + dashboard cards |
| 4.7 | Controlled matrix export in the AI-HR-CM-FM-01 layout (document no., revision, legend) | `/competency/matrix/export` | button on `matrix-tab.tsx` |
| 4.8 | Employee development record (1.9) PDF | `/competency/employees/{employee}/record` | `profile-view.tsx` |

---

## Open questions (answer before the step that needs them)

1. **SOP-HR-004 Training Management**: needed before Phase 3 (request approval, evaluation intervals, provider
   approval).
2. **Authorization activities**: who supplies the list per technical procedure (step 1.8)? Who may grant: HR,
   Operations Manager, or a per-department technical authority? *Built with an empty list and HR granting; give
   `grant-authorizations` to other roles or users to widen it.*
3. **Certification types**: the initial list (e.g. ASNT/ISO 9712 levels per method, LEEA, IRATA, BOSIET, CompEx) for 1.7.
   *Built with an empty list; HR enters them under Certificate types.*
4. **Supervisor review timing** for 1.4: is 90 days after training right?
5. **Critical-role approval** (2.1, 2.6): is Management a role, or specific users (CEO, Head of Administration)?
6. **Contractors** (2.5): flag in `employees` or a separate register?
