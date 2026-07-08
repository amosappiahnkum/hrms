<?php

namespace App\Http\Controllers\Training;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Training\CourseEnrollmentResource;
use App\Http\Resources\Training\CourseResource;
use App\Mail\Training\CourseEnrolledMail;
use App\Models\Appraisal\Assessment;
use App\Models\Appraisal\AssessmentAttempt;
use App\Models\Training\Course;
use App\Models\Training\CourseAssignment;
use App\Models\Training\CourseCategory;
use App\Models\Config\Department;
use App\Models\JobCategory;
use App\Models\Training\CourseEnrollment;
use App\Models\User;
use App\Services\MinioUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CourseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $courses = Course::withCount(['chapters', 'enrollments'])
            ->with('category')
            ->when($request->search, fn($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->when($request->filled('is_published'), fn($q) => $q->where('is_published', $request->boolean('is_published')))
            ->when($request->filled('course_category_id'), fn($q) => $q->where('course_category_id', $request->integer('course_category_id')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return CourseResource::collection($courses);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'course_category_id' => ['nullable', 'exists:course_categories,id'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'passing_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'banner' => ['nullable', 'image', 'max:5120'],
        ]);

        $attributes = $request->only(['title', 'description', 'course_category_id', 'duration_minutes', 'passing_score']);

        if (!isset($attributes['course_category_id'])) {
            $attributes['course_category_id'] = CourseCategory::firstOrCreate(
                ['name' => 'Uncategorized'],
            )->id;
        }

        if ($request->hasFile('banner')) {
            $uploaded = app(MinioUploadService::class)
                ->upload($request->file('banner'), null, 'course-banners');
            $attributes['thumbnail_path'] = $uploaded['path'];

            $attributes['thumbnail_url'] = $uploaded['path'];
        }

        $course = Course::create($attributes);

        activity('training')->performedOn($course)->log("Created course: {$course->title}");

        return ApiResponse::success(CourseResource::make($course), 'Course created.', 201);
    }

    public function show(Course $course): JsonResponse
    {
        $course->load(['chapters.materials.quizAssessment', 'assignments', 'category', 'finalQuiz']);
        $course->loadCount(['chapters', 'enrollments']);

        return ApiResponse::success(CourseResource::make($course));
    }

    public function update(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'course_category_id' => ['nullable', 'exists:course_categories,id'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'passing_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'final_quiz_uuid' => ['nullable', 'exists:assessments,uuid'],
            'banner' => ['nullable', 'image', 'max:5120'],
        ]);

        $attributes = $request->only(['title', 'description', 'course_category_id', 'duration_minutes', 'passing_score']);

        if ($request->has('final_quiz_uuid')) {
            $attributes['final_quiz_id'] = $request->final_quiz_uuid
                ? Assessment::where('uuid', $request->final_quiz_uuid)->value('id')
                : null;
        }

        if ($request->hasFile('banner')) {
            // Delete old banner if exists
            if ($course->thumbnail_path) {
                app(MinioUploadService::class)->delete($course->thumbnail_path);
            }
            $uploaded = app(MinioUploadService::class)
                ->upload($request->file('banner'), null, 'course-banners');
            $attributes['thumbnail_path'] = $uploaded['path'];
            $attributes['thumbnail_url'] = $uploaded['path'];
        }

        $course->update($attributes);

        activity('training')->performedOn($course)->log("Updated course: {$course->title}");

        return ApiResponse::success(CourseResource::make($course));
    }

    public function destroy(Course $course): JsonResponse
    {
        $title = $course->title;
        $course->delete();

        activity('training')->log("Deleted course: {$title}");

        return ApiResponse::success([], 'Course deleted.');
    }

    public function uploadBanner(Request $request, Course $course): JsonResponse
    {
        $request->validate(['banner' => ['required', 'image', 'max:5120']]);

        if ($course->thumbnail_path) {
            app(MinioUploadService::class)->delete($course->thumbnail_path);
        }

        $uploaded = app(MinioUploadService::class)
            ->upload($request->file('banner'), null, 'course-banners');

        $course->update([
            'thumbnail_path' => $uploaded['path'],
            'thumbnail_url' => $uploaded['path'],
        ]);

        return ApiResponse::success(CourseResource::make($course));
    }

    public function publish(Course $course): JsonResponse
    {
        abort_if($course->is_published, 422, 'Course is already published.');

        DB::transaction(function () use ($course) {
            $course->update(['is_published' => true]);
            $this->autoEnroll($course);
        });

        activity('training')->performedOn($course)->log("Published course: {$course->title}");

        return ApiResponse::success(CourseResource::make($course), 'Course published and employees enrolled.');
    }

    public function unpublish(Course $course): JsonResponse
    {
        abort_if(!$course->is_published, 422, 'Course is not published.');

        $course->update(['is_published' => false]);

        activity('training')->performedOn($course)->log("Unpublished course: {$course->title}");

        return ApiResponse::success(CourseResource::make($course), 'Course unpublished.');
    }

    public function assign(Request $request, Course $course): JsonResponse
    {
        $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.scope_type' => ['required', 'in:all,department,job_category,role,employee'],
            'assignments.*.scope_ids' => ['nullable', 'array'],
            'assignments.*.due_date' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($request, $course) {
            $course->assignments()->delete();

            foreach ($request->assignments as $row) {
                CourseAssignment::create([
                    'course_id' => $course->id,
                    'scope_type' => $row['scope_type'],
                    'scope_ids' => $row['scope_type'] === 'all' ? null : ($row['scope_ids'] ?? null),
                    'due_date' => $row['due_date'] ?? null,
                ]);
            }

            if ($course->is_published) {
                $this->syncEnrollments($course);
                $this->autoEnroll($course);
            }
        });

        $course->load('assignments');

        activity('training')->performedOn($course)->log("Updated assignments for course: {$course->title}");

        return ApiResponse::success(CourseResource::make($course), 'Assignments updated.');
    }

    public function enrollments(Request $request, Course $course): AnonymousResourceCollection
    {
        $enrollments = $course->enrollments()
            ->with('user')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $v) => $q->whereHas('user', fn($uq) => $uq->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return CourseEnrollmentResource::collection($enrollments);
    }

    /**
     * Quiz results for all enrolled learners for a specific quiz in this course.
     * GET /training/courses/{course}/quiz-results/{assessment}
     */
    public function quizResults(Request $request, Course $course, Assessment $assessment): JsonResponse
    {
        // Confirm quiz belongs to this course
        abort_unless(
            $assessment->assignable_type === Course::class && $assessment->assignable_id === $course->id,
            403,
            'Quiz does not belong to this course.'
        );

        // Max possible score = sum of (custom_weight ?? question.weight) across all usages
        $maxScore = (float) $assessment->questionUsages()
            ->join('questions', 'questions.id', '=', 'question_usages.question_id')
            ->sum(DB::raw('COALESCE(app_question_usages.custom_weight, app_questions.weight, 1)'));

        $search = $request->input('search');

        $enrollments = $course->enrollments()
            ->with('user')
            ->when($search, fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            ))
            ->latest('enrolled_at')
            ->paginate($request->integer('per_page', 20));

        // Fetch all attempts for this quiz scoped to the current page's enrollments
        $enrollmentIds = $enrollments->pluck('id');
        $attempts = AssessmentAttempt::where('assessment_id', $assessment->id)
            ->whereIn('course_enrollment_id', $enrollmentIds)
            ->get()
            ->keyBy('course_enrollment_id');

        $passingScore = $course->passing_score; // percentage threshold (0–100)

        $data = $enrollments->through(function ($enrollment) use ($attempts, $maxScore, $passingScore) {
            $attempt = $attempts->get($enrollment->id);

            $scorePercent = null;
            $passed       = null;
            if ($attempt && $attempt->score !== null && $maxScore > 0) {
                $scorePercent = round(($attempt->score / $maxScore) * 100, 1);
                if ($passingScore !== null) {
                    $passed = $scorePercent >= $passingScore;
                }
            }

            return [
                'enrollment_uuid'   => $enrollment->uuid,
                'enrollment_status' => $enrollment->status,
                'user' => [
                    'uuid'  => $enrollment->user->uuid,
                    'name'  => $enrollment->user->name,
                    'email' => $enrollment->user->email,
                ],
                'attempt' => $attempt ? [
                    'uuid'         => $attempt->uuid,
                    'status'       => $attempt->status,
                    'score'        => $attempt->score,
                    'score_pct'    => $scorePercent,
                    'passed'       => $passed,
                    'started_at'   => $attempt->started_at?->toISOString(),
                    'submitted_at' => $attempt->submitted_at?->toISOString(),
                ] : null,
            ];
        });

        return ApiResponse::success([
            'quiz' => [
                'uuid'      => $assessment->uuid,
                'title'     => $assessment->title,
                'max_score' => $maxScore,
            ],
            'passing_score' => $passingScore,
            'results'       => $data,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveUserIds(CourseAssignment $assignment): \Illuminate\Support\Collection
    {
        return match ($assignment->scope_type) {
            'all' => User::has('employee')->pluck('id'),
            'department' => User::whereHas('employee', function ($q) use ($assignment) {
                $q->whereIn('department_id', Department::whereIn('uuid', $assignment->scope_ids ?? [])->pluck('id'));
            })->pluck('id'),
            'job_category' => User::whereHas('employee.jobDetail', function ($q) use ($assignment) {
                $q->whereIn('job_category_id', JobCategory::whereIn('uuid', $assignment->scope_ids ?? [])->pluck('id'));
            })->pluck('id'),
            'role'     => User::role($assignment->scope_ids ?? [])->pluck('id'),
            'employee' => User::whereHas('employee', fn($q) => $q->whereIn('uuid', $assignment->scope_ids ?? []))->pluck('id'),
            default    => collect(),
        };
    }

    private function autoEnroll(Course $course): void
    {
        $course->loadMissing('assignments');

        $existingUserIds = $course->enrollments()->pluck('user_id')->flip();

        $allScope = $course->assignments->firstWhere('scope_type', 'all');
        if ($allScope) {
            $toEnroll = $this->resolveUserIds($allScope)
                ->reject(fn($id) => $existingUserIds->has($id));

            $this->insertEnrollments($course, $toEnroll, $allScope->due_date);
            return;
        }

        $userDueDates = [];
        foreach ($course->assignments as $assignment) {
            foreach ($this->resolveUserIds($assignment) as $uid) {
                if (!array_key_exists($uid, $userDueDates)) {
                    $userDueDates[$uid] = $assignment->due_date;
                }
            }
        }

        $toEnroll = collect($userDueDates)
            ->filter(fn($_, $uid) => !$existingUserIds->has($uid));

        $this->insertEnrollments($course, $toEnroll->keys(), null, $toEnroll->all());
    }

    private function syncEnrollments(Course $course): void
    {
        $course->loadMissing('assignments');

        // Build the full set of user IDs that should be enrolled under the current assignments
        $allScope = $course->assignments->firstWhere('scope_type', 'all');
        if ($allScope) {
            // "all" means everyone belongs — nothing to remove
            return;
        }

        $targetUserIds = collect();
        foreach ($course->assignments as $assignment) {
            $targetUserIds = $targetUserIds->merge($this->resolveUserIds($assignment));
        }
        $targetUserIds = $targetUserIds->unique();

        // Remove only enrollments that haven't been started — preserve any progress
        $course->enrollments()
            ->whereIn('status', [CourseEnrollment::STATUS_ENROLLED])
            ->whereNotIn('user_id', $targetUserIds)
            ->delete();
    }

    private function insertEnrollments(Course $course, \Illuminate\Support\Collection $userIds, ?string $sharedDueDate = null, array $perUserDueDates = []): void
    {
        if ($userIds->isEmpty()) return;

        $now = now();

        $rows = $userIds->map(fn($uid) => [
            'course_id'   => $course->id,
            'user_id'     => $uid,
            'uuid'        => Str::uuid(),
            'status'      => CourseEnrollment::STATUS_ENROLLED,
            'enrolled_at' => $now,
            'due_date'    => $sharedDueDate ?? ($perUserDueDates[$uid] ?? null),
            'created_at'  => $now,
            'updated_at'  => $now,
        ])->values()->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            CourseEnrollment::insert($chunk);
        }

        // Queue one enrollment notification per newly enrolled user
        User::whereIn('id', $userIds)
            ->whereNotNull('email')
            ->get(['id', 'name', 'email'])
            ->each(function (User $user) use ($course, $sharedDueDate, $perUserDueDates) {
                $dueDate = $sharedDueDate ?? ($perUserDueDates[$user->id] ?? null);
                Mail::to($user->email)->queue(new CourseEnrolledMail($user, $course, $dueDate));
            });
    }
}
