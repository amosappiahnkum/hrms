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
use App\Http\Resources\TrainingPlan\MyTrainingPlanItemResource;
use App\Http\Resources\TrainingPlan\TrainingPlanItemResource;
use App\Models\SelfService\Employee;
use App\Models\TrainingPlan\TrainingCatalogueItem;
use App\Models\TrainingPlan\TrainingDomain;
use App\Models\TrainingPlan\TrainingPlan;
use App\Models\TrainingPlan\TrainingPlanItem;
use App\Services\TrainingPlan\TrainingPlanAccess;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class TrainingPlanItemController extends Controller
{
    private const RELATIONS = [
        'employee.department', 'catalogueItem', 'domain', 'certifications', 'plan',
        'preparer', 'approver', 'rejecter', 'creator.employee',
    ];

    public function __construct(private readonly TrainingPlanAccess $access) {}

    /** The plan's lines, one per trainee. `training` narrows them to one training (see trainings()). */
    public function index(Request $request, TrainingPlan $trainingPlan): AnonymousResourceCollection
    {
        $items = $this->filtered($trainingPlan->items(), $request)
            ->with(self::RELATIONS)
            ->orderBy('quarter')->orderBy('planned_start_date')->orderBy('id')
            ->paginate($request->integer('per_page', 50));

        return TrainingPlanItemResource::collection($items);
    }

    /**
     * The plan's trainings: lines grouped by catalogue training (or by title for trainings entered
     * by hand), each with a summary of its trainees. Filters apply to the trainees counted.
     */
    public function trainings(Request $request, TrainingPlan $trainingPlan): JsonResponse
    {
        $groups = $this->filtered($trainingPlan->items(), $request)->toBase()
            ->selectRaw("
                training_catalogue_item_id,
                CASE WHEN training_catalogue_item_id IS NULL THEN title END AS custom_title,
                MIN(title) AS title, MIN(nature) AS nature, MIN(training_domain_id) AS training_domain_id,
                COUNT(*) AS trainees,
                SUM(status = 'completed') AS completed,
                SUM(status IN ('cancelled', 'failed')) AS dropped,
                SUM(approval_status = 'approved') AS approved,
                SUM(cost) AS cost,
                MIN(quarter) AS first_quarter,
                GROUP_CONCAT(DISTINCT quarter ORDER BY quarter) AS quarters,
                MIN(planned_start_date) AS starts,
                MAX(planned_end_date) AS ends,
                COUNT(DISTINCT trainer) AS trainer_count, MIN(trainer) AS trainer,
                COUNT(DISTINCT delivery) AS delivery_count, MIN(delivery) AS delivery
            ")
            ->groupBy('training_catalogue_item_id', 'custom_title')
            ->orderBy('first_quarter')->orderBy('title')
            ->paginate($request->integer('per_page', 25));

        $catalogue = TrainingCatalogueItem::withTrashed()
            ->whereIn('id', collect($groups->items())->pluck('training_catalogue_item_id')->filter())
            ->pluck('uuid', 'id');
        $domains = TrainingDomain::withTrashed()
            ->whereIn('id', collect($groups->items())->pluck('training_domain_id')->filter())
            ->pluck('name', 'id');

        $groups->through(function ($g) use ($catalogue, $domains) {
            $catalogueUuid = $g->training_catalogue_item_id ? $catalogue[$g->training_catalogue_item_id] ?? null : null;
            $option = fn (?\BackedEnum $e) => $e ? ['value' => $e->value, 'label' => $e->label()] : null;

            return [
                'key'                 => $catalogueUuid ? "catalogue:{$catalogueUuid}" : "title:{$g->custom_title}",
                'catalogue_item_uuid' => $catalogueUuid,
                'title'               => $g->title,
                'nature'              => $option(TrainingNature::tryFrom((string) $g->nature)),
                'domain'              => $g->training_domain_id ? $domains[$g->training_domain_id] ?? null : null,
                'quarters'            => array_values(array_filter(explode(',', (string) $g->quarters))),
                'starts'              => $g->starts ? Carbon::parse($g->starts)->toDateString() : null,
                'ends'                => $g->ends ? Carbon::parse($g->ends)->toDateString() : null,
                // Shown only when every trainee has the same one.
                'trainer'             => (int) $g->trainer_count === 1 ? $g->trainer : null,
                'delivery'            => (int) $g->delivery_count === 1 ? $option(TrainingDelivery::tryFrom((string) $g->delivery)) : null,
                'trainees'            => (int) $g->trainees,
                'completed'           => (int) $g->completed,
                'dropped'             => (int) $g->dropped,
                'approved'            => (int) $g->approved,
                'cost'                => (float) $g->cost,
            ];
        });

        return ApiResponse::success([
            'data' => $groups->items(),
            'meta' => [
                'current_page' => $groups->currentPage(),
                'per_page'     => $groups->perPage(),
                'total'        => $groups->total(),
                'last_page'    => $groups->lastPage(),
            ],
        ]);
    }

    /**
     * Add trainees to a training already in the plan. They get the same plan details as the
     * training's existing trainees (quarter, dates, trainer, cost…); the personnel category can be set.
     */
    public function addTrainees(Request $request, TrainingPlan $trainingPlan): JsonResponse
    {
        $request->validate([
            'training'         => ['required', 'string'],
            'employee_uuids'   => ['required', 'array', 'min:1', 'max:500'],
            'employee_uuids.*' => ['distinct', 'string', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'category'         => ['nullable', Rule::enum(PersonnelCategory::class)],
        ]);

        $employees = Employee::whereIn('uuid', $request->employee_uuids)->get(['id', 'uuid', 'first_name', 'middle_name', 'last_name']);
        $this->authorizeChange($request, $trainingPlan, $employees->pluck('id'), 'add trainees');

        $template = $this->forTraining($trainingPlan->items(), $request->training)->oldest('id')->first();
        abort_unless($template, 404, 'That training is not in this plan.');

        $data = $template->only([...TrainingPlanItem::PLANNED_FIELDS, 'planned_start_date', 'planned_end_date']);
        unset($data['employee_id']);
        if ($request->filled('category')) {
            $data['category'] = $request->category;
        }

        return $this->createFor($trainingPlan, $data, $employees);
    }

        /** An employee's approved trainings (they stay approved while their plan is revised), for linking a certificate. */
    public function forEmployee(Request $request): JsonResponse
    {
        $request->validate(['employee_uuid' => ['required', 'string', 'exists:employees,uuid']]);

        $items = TrainingPlanItem::query()
            ->with(['plan', 'catalogueItem', 'domain'])
            ->approved()
            ->whereHas('employee', fn ($q) => $q->where('uuid', $request->employee_uuid))
            ->orderByRaw("CASE WHEN status = 'completed' THEN 0 ELSE 1 END")
            ->latest('id')
            ->limit(100)
            ->get();

        return ApiResponse::success(TrainingPlanItemResource::collection($items));
    }

    /**
     * The signed-in employee's own approved trainings, for self-service. Optionally one year;
     * `years` lists every year they have trainings in, newest first.
     */
    public function mine(Request $request): JsonResponse
    {
        $request->validate(['year' => ['nullable', 'integer']]);

        $employeeId = $request->user()->employee_id;
        abort_unless($employeeId, 404, 'Your account is not linked to an employee record.');

        $mine = fn ($q) => $q->approved()->where('employee_id', $employeeId);

        $years = TrainingPlan::whereHas('items', $mine)->orderByDesc('year')->pluck('year');

        $items = TrainingPlanItem::query()->tap($mine)->whereHas('plan')
            ->with(['plan', 'domain', 'certifications'])
            ->when($request->year, fn ($q, $year) => $q->whereHas('plan', fn ($p) => $p->where('year', $year)))
            ->orderBy('quarter')
            ->orderByRaw('planned_start_date IS NULL, planned_start_date')
            ->get();

        return ApiResponse::success([
            'years' => $years,
            'items' => MyTrainingPlanItemResource::collection($items),
        ]);
    }

    public function store(Request $request, TrainingPlan $trainingPlan): JsonResponse
    {
        $this->ensureFromCatalogue($request, true);
        $data = $this->withCatalogueDefaults($this->validated($request)) + ['cost' => 0];
        $this->ensureDatesFit($data, $trainingPlan);

        // One training can be planned for several trainees at once: one item per trainee.
        $employees = Employee::query()
            ->whereIn('uuid', $request->input('employee_uuids', array_filter([$request->input('employee_uuid')])))
            ->get(['id', 'uuid', 'first_name', 'middle_name', 'last_name']);
        unset($data['employee_id']);
        $this->authorizeChange($request, $trainingPlan, $employees->pluck('id'), 'add trainings');

        return $this->createFor($trainingPlan, $data, $employees);
    }

    /** One line per trainee, skipping trainees who already have this training in the plan. */
    private function createFor(TrainingPlan $trainingPlan, array $data, \Illuminate\Support\Collection $employees): JsonResponse
    {
        $already = $trainingPlan->items()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->when(
                $data['training_catalogue_item_id'] ?? null,
                fn ($q, $catalogueId) => $q->where('training_catalogue_item_id', $catalogueId),
                fn ($q) => $q->whereNull('training_catalogue_item_id')->where('title', $data['title']),
            )
            ->pluck('employee_id')->flip();
        [$skipped, $toAdd] = $employees->partition(fn (Employee $e) => $already->has($e->id));

        $created = DB::transaction(fn () => $toAdd->map(fn (Employee $employee) => $trainingPlan->items()->create($data + [
            'employee_id'     => $employee->id,
            'created_by'      => auth()->id(),
            'approval_status' => ApprovalStatus::DRAFT,
        ]))->values());

        $message = match (true) {
            $created->isEmpty()    => 'Everyone selected already has this training in the plan.',
            $skipped->isNotEmpty() => "Added for {$created->count()} trainee(s); {$skipped->count()} already had it.",
            default                => $created->count() === 1 ? 'Training added to the plan.' : "Training added for {$created->count()} trainees.",
        };

        return ApiResponse::success([
            'created' => TrainingPlanItemResource::collection(
                TrainingPlanItem::with(self::RELATIONS)->whereIn('id', $created->pluck('id'))->get()
            ),
            'skipped' => $skipped->map(fn (Employee $e) => ['uuid' => $e->uuid, 'name' => $e->name])->values(),
        ], $message, $created->isEmpty() ? 200 : 201);
    }

    public function update(Request $request, TrainingPlanItem $trainingPlanItem): JsonResponse
    {
        $plan = $trainingPlanItem->plan;
        $data = $this->validated($request, $trainingPlanItem);

        if (!$this->access->canPrepare($request->user())) {
            // Heads of department change what is planned for their staff; progress is HR's.
            if (array_intersect_key($data, array_flip(['status', 'completed_at']))) {
                throw new UserFacingException('Only HR updates the status of a training.', 403);
            }
            $this->authorizeChange($request, $plan, collect([$trainingPlanItem->employee_id, $data['employee_id'] ?? null])->filter(), 'change trainings', $trainingPlanItem);
            $this->ensureFromCatalogue($request, !$trainingPlanItem->training_catalogue_item_id);
        }

        // Re-copy catalogue details only when switching to a different catalogue training.
        if (array_key_exists('training_catalogue_item_id', $data)
            && (int) $data['training_catalogue_item_id'] !== (int) $trainingPlanItem->training_catalogue_item_id) {
            $data = $this->withCatalogueDefaults($data);
        }

        $planned = array_intersect_key($data, array_flip(TrainingPlanItem::PLANNED_FIELDS));
        $plannedChanged = collect($planned)->contains(fn ($value, $field) => $this->differs($trainingPlanItem, $field, $value));

        // What is planned can only change while the plan is being prepared or revised.
        if ($plannedChanged) {
            $this->ensurePlanning($plan, 'change what is planned');
        }

        if (array_key_exists('status', $data) && $this->differs($trainingPlanItem, 'status', $data['status']) && !$trainingPlanItem->isApproved()) {
            throw new UserFacingException('The training status can only be updated once the training is approved.');
        }

        $this->ensureDatesFit($data, $plan, $trainingPlanItem);
        $data = $this->withCompletionDate($data, $trainingPlanItem);

        // An approved training whose planned details change is approved again with the revised plan.
        $reapprove = $plannedChanged && $trainingPlanItem->isApproved();
        if ($reapprove) {
            $data += [
                'approval_status' => ApprovalStatus::DRAFT,
                'approved_by'     => null,
                'approved_at'     => null,
            ];
        }

        $trainingPlanItem->update($data);

        return ApiResponse::success(
            TrainingPlanItemResource::make($trainingPlanItem->fresh(self::RELATIONS)),
            $reapprove ? 'Training updated. It will be approved again with the revised plan.' : 'Training updated.'
        );
    }

    public function destroy(Request $request, TrainingPlanItem $trainingPlanItem): JsonResponse
    {
        $this->authorizeChange($request, $trainingPlanItem->plan, collect([$trainingPlanItem->employee_id]), 'remove trainings', $trainingPlanItem);

        // Approved trainees can be removed during a revision, but a certificate ties the training
        // to the employee's record, so that one is kept (cancel it instead).
        if ($trainingPlanItem->certifications()->exists()) {
            throw new UserFacingException('This trainee has a certificate linked to the training, so it can\'t be removed. Set the status to Cancelled instead.');
        }

        $trainingPlanItem->delete();

        return ApiResponse::success(null, 'Training removed from the plan.');
    }

    /** The list filters, shared by the lines and the grouped trainings. */
    private function filtered($query, Request $request)
    {
        return $this->access->scopeItems($query, $request->user())
            ->when($request->training, fn ($q, $key) => $this->forTraining($q, $key))
            ->when($request->search, fn ($q, $v) => $q->where(fn ($q) => $q->where('title', 'like', "%{$v}%")
                ->orWhereHas('employee', fn ($e) => $e->search($v))))
            ->when($request->department_uuid, fn ($q, $v) => $q->whereHas('employee.department', fn ($d) => $d->where('uuid', $v)))
            ->when($request->employee_uuid, fn ($q, $v) => $q->whereHas('employee', fn ($e) => $e->where('uuid', $v)))
            ->when($request->quarter, fn ($q, $v) => $q->where('quarter', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->approval_status, fn ($q, $v) => $q->where('approval_status', $v))
            ->when($request->nature, fn ($q, $v) => $q->where('nature', $v))
            ->when($request->category, fn ($q, $v) => $q->where('category', $v));
    }

    /** Lines of one training, by its key: "catalogue:{uuid}" or "title:{title}" (entered by hand). */
    private function forTraining($query, string $key)
    {
        [$type, $value] = array_pad(explode(':', $key, 2), 2, '');

        return match ($type) {
            'catalogue' => $query->whereHas('catalogueItem', fn ($c) => $c->withTrashed()->where('uuid', $value)),
            'title'     => $query->whereNull('training_catalogue_item_id')->where('title', $value),
            default     => $query->whereRaw('1 = 0'),
        };
    }

    /** A head of department's staff, to pick trainees from. */
    public function teamEmployees(Request $request): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $employees = $this->access->team($request->user())
            ->with('department')
            ->when($request->search, fn ($q, $v) => $q->search($v))
            ->orderBy('first_name')
            ->limit(50)
            ->get();

        return ApiResponse::success($employees->map(fn (Employee $e) => [
            'uuid'       => $e->uuid,
            'name'       => $e->name,
            'department' => $e->department?->name,
        ])->values());
    }

    /**
     * HR adds, changes and removes trainings while the plan is being prepared. Heads of department
     * do so only for their own staff, only while the plan is collecting, and not for trainings
     * already approved (a revision is HR's).
     */
    private function authorizeChange(Request $request, TrainingPlan $plan, Collection $employeeIds, string $action, ?TrainingPlanItem $item = null): void
    {
        $user = $request->user();

        if ($this->access->canPrepare($user)) {
            $this->ensurePlanning($plan, $action);
            return;
        }

        if (!$plan->isCollecting()) {
            throw new UserFacingException("The plan is not open for training needs, so you can't {$action}. Ask HR to open it.", 403);
        }

        if ($item?->isApproved()) {
            throw new UserFacingException('This training is already approved. Ask HR to change it.', 403);
        }

        if ($employeeIds->isEmpty() || !$this->access->leadsAll($user, $employeeIds)) {
            throw new UserFacingException('You can only plan trainings for staff in the departments you head.', 403);
        }
    }

    /**
     * Only HR plans trainings that are not in the catalogue (and adds to the catalogue). Others pick
     * a catalogue training, whose title, nature and domain come with it. `$required`: the request
     * must name one (adding, or changing a line that has none).
     */
    private function ensureFromCatalogue(Request $request, bool $required): void
    {
        if ($this->access->canPrepare($request->user())) {
            return;
        }

        $clearing = $request->exists('training_catalogue_item_uuid') && !$request->filled('training_catalogue_item_uuid');
        $missing = $required && !$request->filled('training_catalogue_item_uuid');

        if ($clearing || $missing || $request->hasAny(['title', 'nature', 'domain_uuid'])) {
            throw ValidationException::withMessages([
                'training_catalogue_item_uuid' => 'Pick a training from the catalogue. Ask HR to add one that is missing.',
            ]);
        }
    }

    /** Trainings are added, changed or removed only while the plan is a draft or returned for changes. */
    private function ensurePlanning(TrainingPlan $plan, string $action): void
    {
        if ($plan->approval_status->isEditable()) {
            return;
        }

        throw new UserFacingException($plan->isApproved()
            ? "The plan is approved. Revise it to {$action}."
            : "The plan is awaiting validation or approval, so you can't {$action} right now.");
    }

    /**
     * Dates must fall in the plan's year. While planning, the start date must also fall in the
     * training's quarter; after approval a training may be rescheduled into another quarter.
     */
    private function ensureDatesFit(array $data, TrainingPlan $plan, ?TrainingPlanItem $item = null): void
    {
        $start = array_key_exists('planned_start_date', $data) ? $data['planned_start_date'] : $item?->planned_start_date;
        $end = array_key_exists('planned_end_date', $data) ? $data['planned_end_date'] : $item?->planned_end_date;
        $quarter = $data['quarter'] ?? $item?->quarter;
        $errors = [];

        foreach (['planned_start_date' => $start, 'planned_end_date' => $end] as $field => $date) {
            if ($date && Carbon::parse($date)->year !== $plan->year) {
                $errors[$field] = "The date must be in {$plan->year}, the plan's year.";
            }
        }

        if ($start && !isset($errors['planned_start_date']) && $plan->approval_status->isEditable() && $quarter) {
            $startQuarter = 'Q' . Carbon::parse($start)->quarter;
            if ($startQuarter !== $quarter) {
                $errors['planned_start_date'] = "The start date is in {$startQuarter}, but this training is planned for {$quarter}.";
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validated(Request $request, ?TrainingPlanItem $item = null): array
    {
        $required = $item ? 'sometimes' : 'required';
        $fromCatalogue = $request->filled('training_catalogue_item_uuid');

        $data = $request->validate([
            'employee_uuid'                => [$item ? 'sometimes' : 'required_without:employee_uuids', 'string', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'employee_uuids'               => [$item ? 'prohibited' : 'sometimes', 'array', 'min:1', 'max:500'],
            'employee_uuids.*'             => ['distinct', 'string', Rule::exists('employees', 'uuid')->whereNull('deleted_at')],
            'training_catalogue_item_uuid' => ['nullable', 'string', Rule::exists('training_catalogue_items', 'uuid')->whereNull('deleted_at')],
            'title'                        => [$item || $fromCatalogue ? 'sometimes' : 'required', 'string', 'max:255'],
            'nature'                       => [$item || $fromCatalogue ? 'sometimes' : 'required', Rule::enum(TrainingNature::class)],
            'domain_uuid'                  => ['nullable', 'string', Rule::exists('training_domains', 'uuid')->whereNull('deleted_at')],
            'category'                     => [$required, Rule::enum(PersonnelCategory::class)],
            'source_of_need'               => ['nullable', Rule::enum(TrainingNeedSource::class)],
            'supporting_record'            => ['nullable', 'string', 'max:100'],
            'quarter'                      => [$required, Rule::in(TrainingPlanItem::QUARTERS)],
            'days'                         => ['nullable', 'numeric', 'min:0', 'max:365'],
            'cost'                         => ['nullable', 'numeric', 'min:0'],
            'trainer'                      => ['nullable', 'string', 'max:255'],
            'delivery'                     => [$required, Rule::enum(TrainingDelivery::class)],
            'planned_start_date'           => ['nullable', 'date'],
            'planned_end_date'             => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'status'                       => ['sometimes', Rule::enum(TrainingStatus::class)],
            'completed_at'                 => ['nullable', 'date'],
            'comment'                      => ['nullable', 'string', 'max:2000'],
        ]);

        if (array_key_exists('employee_uuid', $data)) {
            $data['employee_id'] = Employee::where('uuid', $data['employee_uuid'])->value('id');
        }

        if (array_key_exists('training_catalogue_item_uuid', $data)) {
            $data['training_catalogue_item_id'] = $data['training_catalogue_item_uuid']
                ? TrainingCatalogueItem::where('uuid', $data['training_catalogue_item_uuid'])->value('id')
                : null;
        }

        if (array_key_exists('cost', $data) && $data['cost'] === null) {
            $data['cost'] = 0;
        }

        unset($data['employee_uuid'], $data['employee_uuids'], $data['training_catalogue_item_uuid']);

        return TrainingDomain::resolveUuid($data);
    }

    /** Copy title, nature, domain, days, cost and trainer from the chosen catalogue entry when not given. */
    private function withCatalogueDefaults(array $data): array
    {
        $catalogue = !empty($data['training_catalogue_item_id'])
            ? TrainingCatalogueItem::find($data['training_catalogue_item_id'])
            : null;

        if (!$catalogue) {
            return $data;
        }

        return $data + [
            'title'              => $catalogue->title,
            'nature'             => $catalogue->nature,
            'training_domain_id' => $catalogue->training_domain_id,
            'days'               => $catalogue->default_days,
            'cost'               => $catalogue->estimated_cost ?? 0,
            'trainer'            => $catalogue->trainer,
        ];
    }

    private function withCompletionDate(array $data, TrainingPlanItem $item): array
    {
        if (!array_key_exists('status', $data)) {
            return $data;
        }

        $status = TrainingStatus::from($data['status'] instanceof TrainingStatus ? $data['status']->value : $data['status']);

        if ($status === TrainingStatus::COMPLETED) {
            $data['completed_at'] = $data['completed_at'] ?? $item->completed_at ?? now()->toDateString();
        } else {
            $data['completed_at'] = null;
        }

        return $data;
    }

    private function differs(TrainingPlanItem $item, string $field, mixed $value): bool
    {
        $current = $item->getAttribute($field);
        $normalise = fn ($v) => $v instanceof \BackedEnum ? $v->value : (is_numeric($v) ? (float) $v : ($v === '' ? null : $v));

        return $normalise($current) !== $normalise($value);
    }
}
