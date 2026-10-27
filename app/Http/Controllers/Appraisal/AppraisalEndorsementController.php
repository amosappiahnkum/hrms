<?php

namespace App\Http\Controllers\Appraisal;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentAttemptResource;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Config\Department;
use App\Models\Payroll\Approval;
use App\Services\Appraisal\AppraisalEndorsement;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Heads above the appraiser endorsing appraisals before HR: their list, endorse or return to the appraiser, and the
 * setting for how far up it goes.
 */
class AppraisalEndorsementController extends Controller
{
    public function __construct(private readonly AppraisalEndorsement $endorsement)
    {
    }

    /** Appraisals waiting for the signed-in head's endorsement (read-only: scores, comments, KPIs). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $ids = Approval::where('process', 'appraisal')->waitingFor($request->user()->id)
            ->where('subject_type', (new AssessmentAttempt())->getMorphClass())->pluck('subject_id');

        return AssessmentAttemptResource::collection(AssessmentAttempt::whereIn('id', $ids)
            ->with(['user.employee.department', 'user.employee.photo', 'user.employee.rank', 'supervisor', 'window.assessment', 'window.questionUsages',
                'responses.question.questionCategory', 'events.actor', 'kpis', 'nextKpis', 'trainings', 'approval'])
            ->latest('submitted_at')->paginate(min((int) $request->input('per_page', 20), 50)));
    }

    public function endorse(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:2000']])['comment'] ?? null;
        $this->endorsement->decide($attempt, $request->user(), true, $comment);

        return ApiResponse::success(AssessmentAttemptResource::make($attempt->fresh()->load('approval')), 'Endorsed.');
    }

    /** Back to the appraiser to revise and confirm again; the reason is required. */
    public function returnToAppraiser(Request $request, AssessmentAttempt $attempt): JsonResponse
    {
        $comment = $request->validate(['comment' => ['required', 'string', 'max:2000']], ['comment.required' => 'Say why it\'s going back.'])['comment'];
        $this->endorsement->decide($attempt, $request->user(), false, $comment);

        return ApiResponse::success(AssessmentAttemptResource::make($attempt->fresh()->load('approval')), 'Returned to the appraiser.');
    }

    public function settings(): JsonResponse
    {
        return ApiResponse::success(['endorsement_chain' => AppraisalEndorsement::rule(), 'department_levels' => Department::levels()]);
    }

    /** How far up endorsement goes: none, a number of heads, up to a level, or the top. For appraisals sent from now on. */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endorsement_chain.stop'  => ['required', Rule::in(['none', 'count', 'level', 'top'])],
            'endorsement_chain.count' => ['nullable', 'required_if:endorsement_chain.stop,count', 'integer', 'between:1,10'],
            'endorsement_chain.level' => ['nullable', 'required_if:endorsement_chain.stop,level', 'integer', 'min:0', 'max:' . max(0, count(Department::levels()) - 1)],
        ])['endorsement_chain'];
        $rule = ['stop' => $data['stop']] + match ($data['stop']) {
            'count' => ['count' => (int) $data['count']],
            'level' => ['level' => (int) $data['level']],
            default => [],
        };
        app(SettingService::class)->set('appraisal.endorsement_chain', $rule);
        activity('appraisals')->causedBy($request->user())->withProperties($rule)->log('Appraisal endorsement changed');

        return ApiResponse::success(['endorsement_chain' => AppraisalEndorsement::rule(), 'department_levels' => Department::levels()], 'Saved.');
    }
}
