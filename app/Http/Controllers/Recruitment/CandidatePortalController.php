<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\RegisterCandidateRequest;
use App\Http\Requests\Recruitment\UpdateCandidateProfileRequest;
use App\Http\Resources\Recruitment\ApplicationResource;
use App\Http\Resources\Recruitment\CandidateAuthResource;
use App\Http\Resources\Recruitment\CandidateProfileResource;
use App\Http\Resources\Recruitment\InterviewResource;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\JobOpening;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CandidatePortalController extends Controller
{
    protected string $guard = 'candidate';

    protected function candidateFromAuth(): Candidate
    {
        return Auth::guard($this->guard)->user();
    }

    /**
     * Register a new candidate and log them in via session cookie.
     */
    public function register(RegisterCandidateRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $candidate = Candidate::create([
                'first_name'  => $request->first_name,
                'middle_name' => $request->middle_name,
                'last_name'   => $request->last_name,
                'email'       => $request->email,
                'phone'       => $request->phone,
                'source'      => $request->source ?? 'portal',
                'password'    => Hash::make($request->password),
            ]);
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            return ApiResponse::error($e->getMessage(), null, 400);
        }

        Auth::guard($this->guard)->login($candidate);

        return response()->json([
            'message' => 'Registration successful',
            'user'    => new CandidateAuthResource($candidate),
        ], 201);
    }

    /**
     * Log in an existing candidate via session cookie.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::guard($this->guard)->attempt($credentials, $request->boolean('remember'))) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        return response()->json([
            'message' => 'Logged in successfully',
            'user'    => new CandidateAuthResource(Auth::guard($this->guard)->user()),
        ]);
    }

    /**
     * Log the candidate out and invalidate their session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard($this->guard)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();
        $candidate->load(['experiences', 'qualifications', 'skills', 'languages', 'documents']);

        return ApiResponse::success(new CandidateProfileResource($candidate));
    }

    public function updateProfile(UpdateCandidateProfileRequest $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        try {
            $candidate->update($request->validated());
            $candidate->load(['experiences', 'qualifications', 'skills', 'languages', 'documents']);

            return ApiResponse::success(new CandidateProfileResource($candidate), 'Profile updated');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function myApplications(Request $request): AnonymousResourceCollection
    {
        $candidate = $this->candidateFromAuth();

        $applications = $candidate->applications()
            ->with(['jobOpening.position', 'jobOpening.department'])
            ->latest()
            ->paginate($request->per_page ?? 10);

        return ApplicationResource::collection($applications);
    }

    public function apply(Request $request): JsonResponse
    {
        $request->validate([
            'job_opening_uuid' => ['required', 'string', 'exists:job_openings,uuid'],
            'cover_letter'     => ['nullable', 'string'],
        ]);

        $candidate  = $this->candidateFromAuth();

        if (!$candidate->profileCompletion()['can_apply']) {
            return ApiResponse::error(
                'Your profile is incomplete. Please add your basic information, work experience, education, and skills before applying.',
                null,
                422
            );
        }

        $jobOpening = JobOpening::where('uuid', $request->job_opening_uuid)->firstOrFail();

        $existing = Application::where('candidate_id', $candidate->id)
            ->where('job_opening_id', $jobOpening->id)
            ->first();

        if ($existing) {
            return ApiResponse::error('You have already applied for this position.', null, 422);
        }

        try {
            $application = Application::create([
                'candidate_id'   => $candidate->id,
                'job_opening_id' => $jobOpening->id,
                'cover_letter'   => $request->cover_letter,
                'applied_at'     => now(),
            ]);

            $application->load(['candidate', 'jobOpening']);

            return ApiResponse::success(new ApplicationResource($application), 'Application submitted', 201);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function applicationDetail(Application $application): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($application->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this application.');
        }

        $application->load(['candidate', 'jobOpening', 'interviews.interviewer', 'offer']);

        return ApiResponse::success(new ApplicationResource($application));
    }

    public function myInterviews(Request $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        $interviews = \App\Models\Recruitment\Interview::query()
            ->whereHas('application', fn($q) => $q->where('candidate_id', $candidate->id))
            ->with(['application.jobOpening'])
            ->latest('scheduled_at')
            ->get();

        return ApiResponse::success(InterviewResource::collection($interviews));
    }
}
