<?php

namespace App\Http\Controllers\TrainingPlan;

use App\Enums\TrainingPlan\EvaluationType;
use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\TrainingPlan\TrainingEvaluation;
use App\Models\User;
use App\Services\TrainingPlan\TrainingEvaluationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service: the feedback and reviews the signed-in user is asked for. Evaluations nobody could
 * be found for (no account, no supervisor) fall to the people preparing training plans.
 */
class TrainingEvaluationController extends Controller
{
    public function __construct(private readonly TrainingEvaluationService $service)
    {
    }

    /** What the user has to answer now, and what they answered lately. */
    public function mine(Request $request): JsonResponse
    {
        if (!$this->service->enabled()) {
            return ApiResponse::success(['pending' => [], 'submitted' => []]);
        }

        $user = $request->user();
        $relations = ['item.employee', 'item.plan', 'evaluator'];

        $pending = $this->askedOf($user)->pending()->whereHas('item')->with($relations)->orderBy('due_on')->get()
            ->filter(fn (TrainingEvaluation $e) => $e->isOpen());
        $submitted = TrainingEvaluation::where('submitted_by', $user->id)->whereHas('item')->with($relations)
            ->latest('submitted_at')->limit(10)->get();

        return ApiResponse::success([
            'pending'   => $pending->map(fn ($e) => $this->payload($e, $user))->values(),
            'submitted' => $submitted->map(fn ($e) => $this->payload($e, $user))->values(),
        ]);
    }

    public function submit(Request $request, TrainingEvaluation $trainingEvaluation): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->service->enabled() && $this->canAnswer($trainingEvaluation, $user), 403, 'This evaluation isn\'t yours to give.');

        if ($trainingEvaluation->isSubmitted()) {
            throw new UserFacingException('This evaluation has already been submitted.', 409);
        }
        if (!$trainingEvaluation->isOpen()) {
            throw new UserFacingException('This review opens on ' . $trainingEvaluation->due_on->format('j M Y') . ', once there has been time to apply the training.');
        }

        $type = $trainingEvaluation->type;
        $rules = [
            'rating'  => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
        foreach (array_keys($type->questions()) as $key) {
            $rules["answers.{$key}"] = ['required', 'integer', 'between:1,5'];
        }
        if ($type === EvaluationType::SUPERVISOR_REVIEW) {
            $rules['applied_on_job'] = ['required', 'boolean'];
            // Not applied: say why, so the gap can be followed up.
            $rules['comment'] = ['required_if:applied_on_job,false,0', 'nullable', 'string', 'max:2000'];
        }
        $data = $request->validate($rules, ['comment.required_if' => 'Say why the training isn\'t being applied.']);

        $trainingEvaluation->update([
            'rating'         => $data['rating'],
            'answers'        => $type->questions() ? array_intersect_key($data['answers'], $type->questions()) : null,
            'applied_on_job' => $type === EvaluationType::SUPERVISOR_REVIEW ? filter_var($data['applied_on_job'], FILTER_VALIDATE_BOOLEAN) : null,
            'comment'        => $data['comment'] ?? null,
            'submitted_at'   => now(),
            'submitted_by'   => $user->id,
        ]);

        return ApiResponse::success($this->payload($trainingEvaluation->fresh(['item.employee', 'item.plan', 'evaluator']), $user), 'Thank you, your evaluation was submitted.');
    }

    /** Evaluations the user is asked for: their own, plus unassigned ones for plan preparers. */
    private function askedOf(User $user): Builder
    {
        return TrainingEvaluation::query()->where(fn ($q) => $q
            ->where('evaluator_id', $user->id)
            ->when($user->can('prepare-training-plan'), fn ($w) => $w->orWhereNull('evaluator_id')));
    }

    private function canAnswer(TrainingEvaluation $evaluation, User $user): bool
    {
        return $evaluation->evaluator_id
            ? $evaluation->evaluator_id === $user->id
            : $user->can('prepare-training-plan');
    }

    private function payload(TrainingEvaluation $e, User $user): array
    {
        $item = $e->item;

        return [
            'uuid'            => $e->uuid,
            'type'            => ['value' => $e->type->value, 'label' => $e->type->label()],
            'status'          => $e->status(),
            'due_on'          => $e->due_on->toDateString(),
            'unassigned'      => $e->evaluator_id === null,
            'training'        => [
                'uuid'         => $item->uuid,
                'title'        => $item->title,
                'year'         => $item->plan?->year,
                'completed_at' => $item->completed_at?->toDateString(),
                'employee'     => $item->employee ? ['uuid' => $item->employee->uuid, 'name' => $item->employee->name] : null,
            ],
            'rating_question' => $e->type->ratingQuestion(),
            'questions'       => collect($e->type->questions())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'rating'          => $e->rating,
            'answers'         => $e->answers,
            'applied_on_job'  => $e->applied_on_job,
            'comment'         => $e->comment,
            'submitted_at'    => $e->submitted_at?->toIso8601String(),
            'can_submit'      => $e->isOpen() && $this->canAnswer($e, $user),
        ];
    }
}
