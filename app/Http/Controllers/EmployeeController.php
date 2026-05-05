<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeExport;
use App\Filters\EmployeeFilter;
use App\Helpers\ApiResponse;
use App\Helpers\Helper;
use App\Helpers\SaveFile;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\TerminateEmployeeRequest;
use App\Http\Requests\UpdateEmployeeJobTypeRequest;
use App\Http\Requests\UpdateEmployeeLevelRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\ArchivedEmployeeResource;
use App\Http\Resources\EmployeeDirectoryResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\MiniEmployeeResource;
use App\Models\ActivityLog;
use App\Models\ContactDetail;
use App\Models\Department;
use App\Models\Employee;
use App\Models\TerminationReason;
use App\Notifications\EmailLinkedNotification;
use App\Services\MinioUploadService;
use App\Traits\InformationUpdate;
use App\Traits\UsePrint;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class EmployeeController extends Controller
{
    use UsePrint, InformationUpdate;

    protected string $docPath = 'images/employees';
    protected array $allowedFiles = ['png', 'jpg', 'jpeg'];

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return AnonymousResourceCollection|BinaryFileResponse
     */
    public function index(Request $request)
    {
        $query = Employee::query()
            ->archived($request->boolean('archived'))
            ->department($request->department)
            ->gender($request->gender)
            ->maritalStatus($request->marital_status)
            ->rank($request->rank_id)
            ->jobCategory($request->job_category_id)
            ->search($request->search);

        if ($request->boolean('export')) {
            return $this->export($query);
        }

        if ($request->boolean('archived')) {
            $query->with('terminationReason');
            return ArchivedEmployeeResource::collection(
                $query->paginate($request->per_page ?? 10)
            );
        }

        return EmployeeResource::collection($query->paginate($request->per_page ?? 10));
    }

    private function export($query)
    {
        $employees = $query->get();

        return Excel::download(
            new EmployeeExport(EmployeeResource::collection($employees)),
            'employees.xlsx'
        );
    }
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function getEmployeeDirectory(Request $request): AnonymousResourceCollection
    {
        $employeesQuery = Employee::query();

        if ($request->filled('search')) {
            $search = $request->query('search');
            $employeesQuery->where(function ($query) use ($search) {
                $query->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('middle_name', 'LIKE', "%{$search}%")
                    ->orWhere('staff_id', 'LIKE', "%{$search}%");
            });
        }

        if ($request->filled('department') && $request->department !== 'all') {
            $department = Department::where('uuid', $request->department)->firstOrFail();
            $employeesQuery->where('department_id', $department->id);
        }


        return EmployeeDirectoryResource::collection($employeesQuery->paginate($request->per_page ?? 10));
    }

    public function getMyTeam(Request $request): AnonymousResourceCollection
    {
        $employee = Auth::user()?->employee;

        $employeesQuery = Employee::query();

        $employeesQuery->where('department_id', $employee->department_id);


        return EmployeeDirectoryResource::collection($employeesQuery->paginate($request->per_page ?? 10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreEmployeeRequest $request
     *
     * @return EmployeeResource|JsonResponse
     * @throws Throwable
     */
    public function store(StoreEmployeeRequest $request)
    {

        DB::beginTransaction();
        try {
            $request['dob'] = $request->dob !== 'null' ? Carbon::parse($request->dob)->format('Y-m-d') : null;
            $employee = Employee::create($request->all());
            $employee->contactDetail()->create();
            $employee->jobDetail()->create();
            DB::commit();

            return new EmployeeResource($employee);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param string $employeeId
     * @return JsonResponse
     */
    public function show(string $employeeId)
    {
        $employee = Employee::query()->where('uuid', $employeeId)->firstOrFail();

        return ApiResponse::success(new EmployeeResource($employee));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     *
     * @return JsonResponse|null
     */
    public function destroy($id): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $employee = Employee::findOrFail($id);
            $employee->delete();
            DB::commit();

            return response()->json([
                'message' => 'Employee Deleted'
            ]);
        } catch (Exception $exception) {
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    public function searchEmployees(Request $request): AnonymousResourceCollection
    {
        $query = $request->query('query');
        $employees = Employee::query()
            ->where('last_name', 'like', '%' . $query . '%')
            ->orWhere('middle_name', 'like', '%' . $query . '%')
            ->orWhere('first_name', 'like', '%' . $query . '%');

        if ($request->filled('slim')) {
            return MiniEmployeeResource::collection($employees->paginate(10));
        }

        return EmployeeResource::collection($employees->paginate(10));
    }

    public function getPeople(): AnonymousResourceCollection
    {
        $employeesQuery = Employee::query();

        if (!$this->getRoles()?->contains('super-admin')) {
            $employeesQuery->where('department_id', Auth::user()->employee->department_id);
        }

        return EmployeeResource::collection($employeesQuery->paginate(10));
    }

    public function getStaff(Request $request): JsonResponse
    {
        $employee = Employee::query()->with('contactDetail')->where('staff_id', $request->staffId)->first();

        if (!$employee) {
            return response()->json([
                'message' => 'Employee not found'
            ], 400);
        }

        return response()->json($employee);
    }

    public function updateStaffMail(Request $request): JsonResponse
    {
        $contact = ContactDetail::find($request->id);

        $employeeName = $contact->employee->first_name;

        $contact->update($request->only(['work_email']));

        Notification::route('mail', $request->work_email)->notify(new EmailLinkedNotification($employeeName));

        return response()->json(["message" => "Email updated successfully"]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param UpdateEmployeeRequest $request
     * @param Employee $employee
     * @return EmployeeResource|JsonResponse
     * @throws Throwable
     */
    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource|JsonResponse
    {
        DB::beginTransaction();
        try {
            if ($this->isHrAdmin()) {
                $employee->update($request->all());
                $employee->save();
            } else {
                $this->infoDifference($employee, $request->all());
                $this->requestUpdate($employee);
            }

            if ($request->has('file') && $request->file !== "null") {
                $saveFile = new SaveFile($employee, $request->file('file'), $this->docPath, $this->allowedFiles);
                $saveFile->save();
            }


            Log::info('emp', [$request->staff_id, $employee?->contactDetail?->phone]);
            Helper::updateSRMS($request->staff_id, $employee?->contactDetail?->phone);

            DB::commit();

            return ApiResponse::success(EmployeeResource::make($employee));
        } catch (Exception $exception) {

            Log::info('Employee update failed', [$exception]);
            return response()->json([
                'message' => "Something went wrong"
            ], 400);
        }
    }

    public function updateEmployeeStatus(UpdateEmployeeJobTypeRequest $request): JsonResponse
    {
        $employee = Employee::query()->where('uuid', $request->employee_id)->first();

        if (!$employee) {
            return response()->json([
                'message' => 'Employee not found',
            ], 404);
        }

        $employee->update($request->only(['job_type']));

        return response()->json([
            'message' => 'Employee status updated successfully',
            'jobType' => $employee->job_type
        ]);
    }

    public function updateEmployeeLevel(UpdateEmployeeLevelRequest $request): JsonResponse
    {
        $employee = Employee::query()->where('uuid', $request->employee_id)->first();

        if (!$employee) {
            return response()->json([
                'message' => 'Record not found',
            ], 404);
        }

        $employee->update($request->only(['level']));

        return response()->json([
            'message' => 'Level updated successfully',
            'level' => $employee->level
        ]);
    }

    /**
     * @throws Throwable
     */
    public function terminateEmployee(TerminateEmployeeRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $employee = Employee::query()->where('uuid', $request->employee_id)->first();

            $terminationReason = TerminationReason::query()->where('uuid', $request->termination_reason_id)->first();

            $employee->update([
                "termination_reason_id" => $terminationReason->id,
                "termination_date" => Carbon::parse($request->effective_date)->format('Y-m-d'),
                "terminated_by" => auth()->id()
            ]);

            $employee?->userAccount()?->delete();
            $employee->delete();

            DB::commit();
            return response()->json([
                'message' => 'Employee terminated successfully'
            ]);

        } catch (\Exception $exception) {
            DB::rollBack();
            Log::info('Employee termination failed', [$exception]);
            return response()->json([
                'message' => "Could not terminate employee"
            ]);
        }
    }

    /**
     * @throws Exception
     */
    public function uploadPhoto(Request $request, MinioUploadService $minio)
    {
        $request->validate([
            'file' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120', // 5MB max
        ]);

        $employee = Employee::query()->where('uuid', $request->employee_id)->first();

        $result = $minio->upload($request->file('file'), $employee->staff_id, 'photos');

        $employee->update(['photo' => $result['filename']]);

        $result['employee_id'] = $employee->uuid;

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function getPhoto($fileName, MinioUploadService $minio)
    {
        return response($minio->getFile($fileName), 200)
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function onboardEmployee(Request $request)
    {
        DB::beginTransaction();
        try {
            $employee = Employee::where('uuid', $request->employee_id)->firstOrFail();

            $employee->fill([
                "title" => $request->title,
                "first_name" => $request->first_name,
                "middle_name" => $request->other_names,
                "last_name" => $request->last_name,
                "staff_id" => $request->staff_id,
                "gender" => $request->gender,
                "marital_status" => $request->marital_status,
                "department_id" => $request->department_id,
                "job_type" => $request->job_type,
                "onboarding" => true
            ]);

            $employee->save();

            $jobDetail = $employee->jobDetail;

            $jobDetail->job_category_id = $request->job_category_id;
            $jobDetail->save();

            DB::commit();

            $emp = $employee->fresh();
            return response()->json([
                'success' => true,
                'data' => [
                    'first_name' => $emp->first_name,
                    'onboarding' => $emp->onboarding,
                    'staff_id' => $emp->staff_id,
                ]
            ]);
        } catch (\Exception $exception) {
            DB::rollBack();
            Log::info('Employee update failed', [$exception]);

            return response()->json([
                'message' => "Something went wrong"
            ], 400);
        }
    }

    /**
     * @param Employee $employee
     * @return JsonResponse
     */
    public function getSpecializations(Employee $employee)
    {
        return ApiResponse::success($employee->specializations, 'Specializations');
    }

    public function updateSpecializations(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'specializations' => ['required', 'array'],
            'specializations.*' => ['string', 'distinct'],
        ]);

        $specializations = array_values(array_unique([
            ...($employee->specializations ?? []),
            ...$validated['specializations'],
        ]));

        $employee->update(['specializations' => $specializations]);

        return ApiResponse::success([
            'specializations' => $specializations,
        ], 'Specializations updated successfully');
    }

    public function removeSpecialization(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'specialization' => ['required', 'string'],
        ]);

        $employee->specializations = array_values(array_filter(
            $employee->specializations ?? [], fn ($item) => $item !== $validated['specialization']
        ));

        $employee->save();

        return ApiResponse::success($employee->specializations);
    }

    /**
     * @param Employee $employee
     * @return JsonResponse
     */
    public function getResearchInterests(Employee $employee)
    {
        return ApiResponse::success($employee->research_interests, 'Research Interests');
    }

    public function updateResearchInterests(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'research_interests' => ['required', 'array'],
            'research_interests.*' => ['string', 'distinct'],
        ]);

        $research_interests = array_values(array_unique([
            ...($employee->research_interests ?? []),
            ...$validated['research_interests'],
        ]));

        $employee->update(['research_interests' => $research_interests]);

        return ApiResponse::success([
            'specializations' => $research_interests,
        ], 'Research interests updated successfully');
    }

    public function removeResearchInterest(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'research_interest' => ['required', 'string'],
        ]);

        $employee->research_interests = array_values(array_filter(
            $employee->research_interests ?? [], fn ($item) => $item !== $validated['research_interest']
        ));

        $employee->save();

        return ApiResponse::success($employee->research_interests);
    }

    public function employeeStats(string $uuid)
    {
        $stats = $this->empStats($uuid);

        return ApiResponse::success($stats);
    }

    /**
     * @param Employee $employee
     * @return JsonResponse
     */
    public function getBiography(Employee $employee)
    {
        return ApiResponse::success($employee->bio, 'Biography');
    }

    public function updateBiography(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'biography' => 'required|string|max:1500'
        ]);

        $employee->update(['bio' => $validated['biography']]);

        return ApiResponse::success($employee->bio, 'Specializations updated successfully');
    }
}
