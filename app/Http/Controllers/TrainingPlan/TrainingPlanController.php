<?php

namespace App\Http\Controllers\TrainingPlan;

use App\Enums\TrainingPlan\ApprovalStatus;
use App\Enums\TrainingPlan\PersonnelCategory;
use App\Enums\TrainingPlan\TrainingDelivery;
use App\Enums\TrainingPlan\TrainingNature;
use App\Enums\TrainingPlan\TrainingNeedSource;
use App\Enums\TrainingPlan\TrainingStatus;
use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\TrainingPlan\TrainingPlanResource;
use App\Models\TrainingPlan\TrainingCatalogueItem;
use App\Models\TrainingPlan\TrainingPlan;
use App\Models\TrainingPlan\TrainingPlanItem;
use App\Models\Config\Department;
use App\Models\User;
use App\Notifications\TrainingPlanCollectionNotification;
use App\Services\TrainingPlan\ApprovalService;
use App\Services\TrainingPlan\TrainingPlanAccess;
use Illuminate\Support\Facades\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Throwable;

class TrainingPlanController extends Controller
{
    private const TRAIL = ['preparer', 'approver', 'rejecter'];

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly TrainingPlanAccess $access,
    ) {}

    /** What the signed-in user can do with training plans, for the menus and pages. */
    public function access(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'sees_everything'   => $this->access->seesEverything($user),
            'can_prepare'       => $this->access->canPrepare($user),
            'heads_departments' => $this->access->headsDepartments($user),
            'can_configure'     => $user->can('configure-training-plan-approvals'),
        ]);
    }

    /** Dropdown values for plan and item forms. */
    public function options(): JsonResponse
    {
        $list = fn (string $enum) => collect($enum::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->values();
        $distinct = fn (string $column) => TrainingCatalogueItem::query()->whereNotNull($column)->distinct()->pluck($column)
            ->merge(TrainingPlanItem::query()->whereNotNull($column)->distinct()->pluck($column))
            ->map(fn ($v) => trim($v))->filter()->unique()->sort()->values();

        return ApiResponse::success([
            'natures'         => $list(TrainingNature::class),
            'categories'      => $list(PersonnelCategory::class),
            'sources_of_need' => collect(TrainingNeedSource::cases())->map(fn ($c) => [
                'value' => $c->value, 'label' => $c->label(), 'supporting_record' => $c->supportingRecord(),
            ])->values(),
            'statuses'        => $list(TrainingStatus::class),
            'deliveries'      => $list(TrainingDelivery::class),
            'approval_statuses' => $list(ApprovalStatus::class),
            'quarters'        => TrainingPlanItem::QUARTERS,
            'trainers'        => $distinct('trainer'),
        ]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $plans = TrainingPlan::query()
            ->with(self::TRAIL)
            ->withCount(['items', 'items as approved_items_count' => fn ($q) => $q->where('approval_status', ApprovalStatus::APPROVED->value)])
            ->withSum(['items as planned_cost' => fn ($q) => $q->where('approval_status', ApprovalStatus::APPROVED->value)], 'cost')
            ->when($request->year, fn ($q, $v) => $q->where('year', $v))
            ->when($request->approval_status, fn ($q, $v) => $q->where('approval_status', $v))
            ->orderByDesc('year')->latest('id')
            ->paginate($request->integer('per_page', 20));

        return TrainingPlanResource::collection($plans);
    }

    public function show(TrainingPlan $trainingPlan): JsonResponse
    {
        return ApiResponse::success(TrainingPlanResource::make($this->loaded($trainingPlan)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $plan = TrainingPlan::create($data + [
            'title'      => $data['title'] ?? "{$data['year']} Annual Training Plan",
            'created_by' => auth()->id(),
        ]);

        return ApiResponse::success(TrainingPlanResource::make($plan->load(self::TRAIL)), 'Training plan created.', 201);
    }

    public function update(Request $request, TrainingPlan $trainingPlan): JsonResponse
    {
        $this->ensureEditable($trainingPlan);
        $trainingPlan->update($this->validated($request, $trainingPlan));

        return ApiResponse::success(TrainingPlanResource::make($trainingPlan->load(self::TRAIL)), 'Training plan updated.');
    }

    public function destroy(TrainingPlan $trainingPlan): JsonResponse
    {
        $this->ensureEditable($trainingPlan);
        $trainingPlan->items()->delete();
        $trainingPlan->delete();

        return ApiResponse::success(null, 'Training plan deleted.');
    }

    /**
     * Open the plan for heads of department to add their staff's training needs between two dates.
     * When the window is open today, heads of department are told by email.
     */
    public function openCollection(Request $request, TrainingPlan $trainingPlan): JsonResponse
    {
        $this->ensureEditable($trainingPlan);

        $data = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on'   => ['required', 'date', 'after_or_equal:starts_on', 'after_or_equal:today'],
            'notify'    => ['sometimes', 'boolean'],
        ]);

        $wasCollecting = $trainingPlan->isCollecting();
        $trainingPlan->update(['collection_starts_on' => $data['starts_on'], 'collection_ends_on' => $data['ends_on']]);

        $notified = 0;
        if ($trainingPlan->isCollecting() && !$wasCollecting && $request->boolean('notify', true)) {
            $heads = User::whereIn('employee_id', Department::whereNotNull('hod')->pluck('hod'))->get();
            Notification::send($heads, new TrainingPlanCollectionNotification($trainingPlan));
            $notified = $heads->count();
        }

        return ApiResponse::success(
            TrainingPlanResource::make($this->loaded($trainingPlan)),
            $notified ? "Heads of department can add trainings until {$trainingPlan->collection_ends_on->format('j M Y')}. {$notified} notified." : 'Collection dates saved.'
        );
    }

    /** Stop heads of department adding trainings (what they added stays in the plan). */
    public function closeCollection(TrainingPlan $trainingPlan): JsonResponse
    {
        $yesterday = today()->subDay();
        $trainingPlan->update([
            'collection_starts_on' => $trainingPlan->collection_starts_on?->min($yesterday),
            'collection_ends_on'   => $trainingPlan->collection_starts_on ? $yesterday : null,
        ]);

        return ApiResponse::success(TrainingPlanResource::make($this->loaded($trainingPlan)), 'Collection closed.');
    }

    public function submit(TrainingPlan $trainingPlan): JsonResponse
    {
        return $this->transition(fn () => $this->approvals->submit($trainingPlan, auth()->user()), $trainingPlan, 'Plan sent for validation.');
    }

    public function signOff(TrainingPlan $trainingPlan): JsonResponse
    {
        $wasFinal = $trainingPlan->isFinalLevel();

        return $this->transition(fn () => $this->approvals->signOff($trainingPlan, auth()->user()), $trainingPlan, $wasFinal ? 'Signed.' : 'Validated.');
    }

    public function revise(TrainingPlan $trainingPlan): JsonResponse
    {
        return $this->transition(fn () => $this->approvals->revise($trainingPlan, auth()->user()), $trainingPlan, 'Plan reopened for revision.');
    }

    public function reject(Request $request, TrainingPlan $trainingPlan): JsonResponse
    {
        $request->validate(['comment' => ['required', 'string', 'max:2000']]);

        return $this->transition(fn () => $this->approvals->reject($trainingPlan, auth()->user(), $request->comment), $trainingPlan, 'Plan rejected.');
    }

    /**
     * The plan's analysis (spreadsheet "Analysis" sheet). For an approved plan it counts approved items,
     * reporting items still awaiting sign-off under "pending"; a plan being prepared shows its draft figures.
     */
    public function dashboard(Request $request, TrainingPlan $trainingPlan): JsonResponse
    {
        abort_unless($this->access->seesEverything($request->user()), 403, 'Only HR and the plan\'s reviewers see the whole plan.');

        $items = $trainingPlan->items()->get(['id', 'employee_id', 'nature', 'category', 'quarter', 'status', 'delivery', 'cost', 'actual_cost', 'hours', 'approval_status']);

        // Until the plan is approved, show its draft figures (everything in it) rather than zeros.
        $draft = !$trainingPlan->isApproved();
        [$approved, $pending] = $draft
            ? [$items, collect()]
            : $items->partition(fn (TrainingPlanItem $i) => $i->isApproved());

        $planned = (float) $approved->sum('cost');
        $completedItems = $approved->filter(fn ($i) => $i->status === TrainingStatus::COMPLETED);
        // What completed trainings actually cost, where recorded; otherwise what was planned.
        $executed = (float) $completedItems->sum(fn ($i) => $i->actual_cost ?? $i->cost);
        $factor = (float) $trainingPlan->budget_factor ?: 1.0;

        $breakdown = fn (array $cases, callable $key) => collect($cases)->map(function ($case) use ($approved, $key) {
            $group = $approved->filter(fn ($i) => $key($i) === $case);
            $completed = $group->filter(fn ($i) => $i->status === TrainingStatus::COMPLETED)->count();

            return [
                'value'     => is_string($case) ? $case : $case->value,
                'label'     => is_string($case) ? $case : $case->label(),
                'planned'   => $group->count(),
                'completed' => $completed,
                'rate'      => $group->count() ? round($completed / $group->count(), 4) : null,
            ];
        })->values();

        return ApiResponse::success([
            'draft'  => $draft,
            'budget' => [
                'planned'   => round($planned, 2),
                'estimated' => round($planned / $factor, 2),
                'executed'  => round($executed, 2),
                'remaining' => round($planned - $executed, 2),
            ],
            'totals' => [
                'trainings' => $approved->count(),
                'trainees'  => $approved->pluck('employee_id')->unique()->count(),
                'completed' => $completedItems->count(),
                'hours'     => round((float) $completedItems->sum('hours'), 1),
            ],
            'by_nature'   => $breakdown(TrainingNature::cases(), fn ($i) => $i->nature),
            'by_category' => $breakdown(PersonnelCategory::cases(), fn ($i) => $i->category),
            'by_quarter'  => $breakdown(TrainingPlanItem::QUARTERS, fn ($i) => $i->quarter),
            'by_status'   => $this->counts($approved, TrainingStatus::cases(), fn ($i) => $i->status),
            'by_delivery' => $this->counts($approved, TrainingDelivery::cases(), fn ($i) => $i->delivery),
            'pending'     => ['count' => $pending->count(), 'cost' => round((float) $pending->sum('cost'), 2)],
        ]);
    }

    private function counts(Collection $items, array $cases, callable $key): Collection
    {
        return collect($cases)->map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'count' => $items->filter(fn ($i) => $key($i) === $case)->count(),
        ])->values();
    }

    private function transition(callable $action, TrainingPlan $plan, string $message): JsonResponse
    {
        try {
            $action();
        } catch (Throwable $e) {
            return ApiResponse::fromException($e);
        }

        return ApiResponse::success(TrainingPlanResource::make($this->loaded($plan->fresh())), $message);
    }

    private function loaded(TrainingPlan $plan): TrainingPlan
    {
        return $plan->load(self::TRAIL)->loadCount(['items', 'items as approved_items_count' => fn ($q) => $q->where('approval_status', ApprovalStatus::APPROVED->value)]);
    }

    private function ensureEditable(TrainingPlan $plan): void
    {
        if (!$plan->approval_status->isEditable()) {
            throw new UserFacingException('Only a draft or rejected plan can be changed or deleted.');
        }
    }

    private function validated(Request $request, ?TrainingPlan $plan = null): array
    {
        return $request->validate([
            // One plan per year: the year identifies the plan.
            'year'          => [$plan ? 'sometimes' : 'required', 'integer', 'between:2000,2100',
                Rule::unique('training_plans', 'year')->ignore($plan?->id)->whereNull('deleted_at')],
            'title'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'budget_factor' => ['sometimes', 'numeric', 'min:0.1', 'max:1'],
        ], [
            'year.unique' => 'There is already a training plan for this year.',
        ]);
    }
}
