<?php

namespace App\Http\Controllers\Appraisal;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppraisalTemplateRequest;
use App\Http\Resources\AppraisalTemplateResource;
use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentWindow;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppraisalTemplateController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Assessment::withCount('questionUsages as questions_count')
            ->with('jobCategories')
            ->latest();

        if ($request->filled('types')) {
            $query->whereIn('type', (array) $request->input('types'));
        } elseif ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where('title', 'like', "%{$search}%");
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $templates = $query->paginate($request->input('per_page', 15));

        return AppraisalTemplateResource::collection($templates);
    }

    public function store(StoreAppraisalTemplateRequest $request): JsonResponse
    {
        $template = Assessment::create([
            'title'       => $request->name,
            'type'        => $request->type,
            'description' => $request->description,
            'is_active'   => $request->input('is_active', true),
            'include_training_section' => $request->boolean('include_training_section'),
            'include_next_period_targets' => $request->boolean('include_next_period_targets'),
            'supervisor_weight' => $request->input('supervisor_weight', 60),
        ]);

        $template->jobCategories()->sync($request->input('job_category_ids', []));
        $template->load('jobCategories');

        activity('appraisals')->performedOn($template)->log("Created {$template->type} template: {$template->title}");

        return ApiResponse::success(AppraisalTemplateResource::make($template), 'Template created.', 201);
    }

    public function show(Assessment $assessment): JsonResponse
    {
        $assessment->load(['jobCategories', 'questionUsages']);

        return ApiResponse::success(AppraisalTemplateResource::make($assessment));
    }

    public function preview(Assessment $assessment): JsonResponse
    {
        $assessment->load(['questionUsages.question.options', 'questionUsages.question.dependsOn']);

        $questions = $assessment->questionUsages
            ->sortBy('order')
            ->values()
            ->map(fn ($usage) => $usage->toQuestionPayload());

        return ApiResponse::success([
            'name'      => $assessment->title,
            'type'      => $assessment->type,
            'include_training_section' => (bool) $assessment->include_training_section,
            'supervisor_weight' => $assessment->supervisor_weight,
            'questions' => $questions,
        ]);
    }

    public function update(StoreAppraisalTemplateRequest $request, Assessment $assessment): JsonResponse
    {
        $assessment->update([
            'title'       => $request->name,
            'type'        => $request->type,
            'description' => $request->description,
            'is_active'   => $request->input('is_active', $assessment->is_active),
            'include_training_section' => $request->boolean('include_training_section', $assessment->include_training_section),
            'include_next_period_targets' => $request->boolean('include_next_period_targets', $assessment->include_next_period_targets),
            'supervisor_weight' => $request->input('supervisor_weight', $assessment->supervisor_weight),
        ]);

        $assessment->jobCategories()->sync($request->input('job_category_ids', []));
        $assessment->load('jobCategories');

        activity('appraisals')->performedOn($assessment)->log("Updated {$assessment->type} template: {$assessment->title}");

        return ApiResponse::success(AppraisalTemplateResource::make($assessment));
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        $title = $assessment->title;

        QuestionUsage::where('usable_type', Assessment::class)
            ->where('usable_id', $assessment->id)
            ->delete();

        $assessment->delete();

        activity('appraisals')->log("Deleted template: {$title}");

        return ApiResponse::success([], 'Template deleted.');
    }

    public function addQuestion(Request $request, Assessment $assessment): JsonResponse
    {
        if ($guard = $this->guardActiveSession($assessment)) return $guard;
        $request->validate([
            'question_uuid' => ['required', 'exists:questions,uuid'],
            'order'         => ['nullable', 'integer', 'min:0'],
            'custom_weight' => ['nullable', 'numeric', 'min:0'],
            'respondent'    => ['nullable', 'in:' . QuestionUsage::RESPONDENT_EMPLOYEE . ',' . QuestionUsage::RESPONDENT_SUPERVISOR],
        ]);

        $question = Question::where('uuid', $request->question_uuid)->firstOrFail();
        $usage    = $assessment->addQuestion($question, $request->input('order', 0), $request->custom_weight, $request->input('respondent', QuestionUsage::RESPONDENT_EMPLOYEE));

        return ApiResponse::success([
            'usage_uuid' => $usage->uuid,
            'order'      => $usage->order,
            'respondent' => $usage->respondent,
            'question'   => $question->only(['uuid', 'text', 'type']),
        ], 'Question added.');
    }

    public function removeQuestion(Assessment $assessment, Question $question): JsonResponse
    {
        if ($guard = $this->guardActiveSession($assessment)) return $guard;

        $assessment->removeQuestion($question);

        return ApiResponse::success([], 'Question removed.');
    }

    /** Prevent question edits when any open session already snapshotted this template. */
    private function guardActiveSession(Assessment $assessment): ?JsonResponse
    {
        $hasActiveSession = AssessmentWindow::where('assessment_id', $assessment->id)
            ->where('status', 'open')
            ->exists();

        if ($hasActiveSession) {
            return response()->json([
                'message' => 'This template has an open session. Close the session before modifying questions.',
            ], 422);
        }

        return null;
    }

    public function syncQuestions(Request $request, Assessment $assessment): JsonResponse
    {
        if ($guard = $this->guardActiveSession($assessment)) return $guard;
        $request->validate([
            'questions'                 => ['required', 'array'],
            'questions.*.uuid'          => ['required', 'exists:questions,uuid'],
            'questions.*.order'         => ['nullable', 'integer', 'min:0'],
            'questions.*.custom_weight' => ['nullable', 'numeric', 'min:0'],
            // Appraisals: who answers it (default: the employee)
            'questions.*.respondent'    => ['nullable', 'in:' . QuestionUsage::RESPONDENT_EMPLOYEE . ',' . QuestionUsage::RESPONDENT_SUPERVISOR],
        ]);

        QuestionUsage::where('usable_type', Assessment::class)
            ->where('usable_id', $assessment->id)
            ->delete();

        foreach ($request->questions as $index => $item) {
            $question = Question::where('uuid', $item['uuid'])->firstOrFail();
            QuestionUsage::create([
                'question_id'   => $question->id,
                'usable_type'   => Assessment::class,
                'usable_id'     => $assessment->id,
                'order'         => $item['order'] ?? $index,
                'custom_weight' => $item['custom_weight'] ?? null,
                'respondent'    => $item['respondent'] ?? QuestionUsage::RESPONDENT_EMPLOYEE,
                'user_id'       => auth()->id(),
            ]);
        }

        $assessment->load(['jobCategories', 'questionUsages']);

        activity('appraisals')->performedOn($assessment)
            ->withProperties(['question_count' => count($request->questions)])
            ->log("Synced questions on template: {$assessment->title}");

        return ApiResponse::success(AppraisalTemplateResource::make($assessment));
    }
}
