<?php

namespace App\Http\Resources;

use App\Models\QuestionBank\QuestionUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Direct review flow: the employee doesn't see the appraiser's changes, comment or the score gap.
        $viewerId = $request->user()?->id;
        $hide     = $this->hidesReviewFrom($viewerId);
        // The supervisor's own section: hidden from the employee in the direct flow and until the supervisor is done.
        $showSupervisorSection = $this->showsSupervisorSectionTo($viewerId);
        $supervisorQuestionIds = $this->relationLoaded('window') && $this->window?->relationLoaded('questionUsages')
            ? $this->questionUsagesFor(QuestionUsage::RESPONDENT_SUPERVISOR)->pluck('question_id')->all()
            : [];

        return [
            'uuid'                    => $this->uuid,
            'status'                  => $this->status,
            'review_flow'             => $this->review_flow ?? \App\Models\Appraisal\AssessmentAttempt::REVIEW_FLOW_ACKNOWLEDGE,
            'is_editable'             => $this->isEditable(),
            'started_at'              => $this->started_at?->toDateTimeString(),
            'submitted_at'            => $this->submitted_at?->toDateTimeString(),

            // Employee comment (appraisal only)
            'employee_comment'        => $this->employee_comment,

            // Supervisor review (appraisal only)
            'supervisor'              => $this->when($this->supervisor_id, fn () => [
                'uuid' => $this->supervisor?->uuid,
                'name' => $this->supervisor?->name,
            ]),
            'supervisor_comment'      => $this->supervisorCommentFor($viewerId),
            'supervisor_confirmed_at' => $this->supervisor_confirmed_at?->toDateTimeString(),

            // Frozen scores — set when HR/appraisal officer finalises
            'score'        => $this->score,
            'self_score'   => $hide ? null : $this->self_score,
            'kpi_score'    => $this->kpi_score,
            'employee_section_score'   => $hide ? null : $this->employee_section_score,
            'supervisor_section_score' => $showSupervisorSection ? $this->supervisor_section_score : null,
            'finalized_at'        => $this->finalized_at?->toDateTimeString(),
            'employee_signed_at'  => $this->employee_signed_at?->toDateTimeString(),
            'supervisor_signed_at' => $this->supervisor_signed_at?->toDateTimeString(),

            'window'          => $this->whenLoaded('window', fn () =>
                AssessmentWindowResource::make($this->window)
            ),
            'user'            => $this->whenLoaded('user', fn () =>
                $this->user->employee
                    ? MiniEmployeeResource::make($this->user->employee)
                    : null
            ),
            'responses'       => $this->whenLoaded('responses', fn () =>
                $this->responses
                    ->reject(fn ($r) => !$showSupervisorSection && in_array($r->question_id, $supervisorQuestionIds, true))
                    ->values()
                    ->map(fn ($r) => [
                    'respondent' => in_array($r->question_id, $supervisorQuestionIds, true)
                        ? QuestionUsage::RESPONDENT_SUPERVISOR
                        : QuestionUsage::RESPONDENT_EMPLOYEE,
                    // Every change the appraiser made to it (HR, appraiser, endorsers)
                    ...($r->relationLoaded('revisions') && !$hide
                        ? ['revisions' => $r->revisions->map->toSummary()->values()]
                        : []),
                    'response_uuid'          => $r->uuid,
                    'question_uuid'          => $r->question?->uuid,
                    'question_text'          => $r->question?->text,
                    'question_type'          => $r->question?->type,
                    'question_options'       => $r->question?->options->map(fn ($o) => [
                        'uuid'         => $o->uuid,
                        'option_text'  => $o->option_text,
                        'option_value' => $o->option_value,
                        'order'        => $o->order,
                    ]) ?? [],
                    'answer'                 => $r->answer,
                    'score'                  => $r->score,
                    'supervisor_answer'         => $hide ? null : $r->supervisor_answer,
                    'supervisor_score'          => $hide ? null : $r->supervisor_score,
                    'supervisor_justification'  => $hide ? null : $r->supervisor_justification,
                    'supervisor_updated_at'     => $hide ? null : $r->supervisor_updated_at?->toDateTimeString(),
                ])
            ),
            // The questions the supervisor answers at review (appraisals with a supervisor section).
            'supervisor_questions' => $this->when(
                $showSupervisorSection && $supervisorQuestionIds !== [],
                fn () => $this->questionUsagesFor(QuestionUsage::RESPONDENT_SUPERVISOR)
                    ->sortBy('order')->values()->map(fn ($u) => $u->toQuestionPayload())
            ),
            'responses_count' => $this->when(isset($this->responses_count), $this->responses_count),
            // The heads endorsing it before HR: each step, who decided and when (when loaded).
            'endorsement'     => $this->whenLoaded('approval', fn () => $this->approval && !$hide
                ? \App\Http\Controllers\Payroll\ApprovalController::summary($this->approval->loadMissing('decisions.decider'))
                : null),
            'events'          => $this->whenLoaded('events', fn () =>
                AssessmentAttemptEventResource::collection($this->eventsVisibleTo($viewerId))
            ),
            'kpis'        => $this->whenLoaded('kpis', fn () =>
                AppraisalKpiResource::collection($this->kpis)
            ),
            'next_kpis'   => $this->whenLoaded('nextKpis', fn () =>
                EmployeeKpiResource::collection($this->nextKpis)
            ),
            'trainings'   => $this->whenLoaded('trainings', fn () =>
                AppraisalTrainingResource::collection($this->trainings)
            ),
            'kpi_summary' => $this->when(!$hide && $this->relationLoaded('responses'), fn () =>
                $showSupervisorSection
                    ? $this->computeKpiSummary()
                    : array_diff_key($this->computeKpiSummary(), ['supervisor_section_score' => true, 'overall_score' => true, 'score_gap' => true])
            ),
        ];
    }
}
