<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeExport;
use App\Helpers\Helper;
use App\Helpers\SaveFile;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\TerminateEmployeeRequest;
use App\Http\Requests\UpdateEmployeeLevelRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Requests\UpdateEmployeeJobTypeRequest;
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
use Illuminate\Support\Facades\Http;
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
     * @return AnonymousResourceCollection|Response|BinaryFileResponse
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function index(Request $request)
    {
        if ($request->filled('archived') && $request->archived == 'true') {
            $employeesQuery = Employee::onlyTrashed();
        } else {
            $employeesQuery = Employee::query();
        }

        // Filter by department
        if ($request->filled('department') && $request->department !== 'all') {
            $employeesQuery->where('department_id', $request->department);
        }

        // Filter by department
        if ($request->filled('gender') && $request->gender !== 'all') {
            $employeesQuery->where('gender', $request->gender);
        }

        // Filter by department
        if ($request->filled('marital_status') && $request->marital_status !== 'all') {
            $employeesQuery->where('marital_status', $request->marital_status);
        }

        // Search by name fields
        if ($request->filled('search')) {
            $search = $request->query('search');
            $employeesQuery->where(function ($query) use ($search) {
                $query->where('first_name', 'LIKE', "%{$search}%")
                    ->orWhere('last_name', 'LIKE', "%{$search}%")
                    ->orWhere('middle_name', 'LIKE', "%{$search}%")
                    ->orWhere('staff_id', 'LIKE', "%{$search}%");
            });
        }

        // Filter by rank
        if ($request->filled('rank_id') && $request->rank_id !== 'all') {
            $employeesQuery->where('rank_id', $request->rank_id);
        }

        // Filter by job category via relation
        if ($request->filled('job_category_id') && $request->job_category_id !== 'all') {
            $employeesQuery->whereHas('jobDetail', function ($query) use ($request) {
                $query->where('job_category_id', $request->job_category_id);
            });
        }

        // Export to Excel
        if ($request->boolean('export')) {
            $employees = $employeesQuery->get();
            return Excel::download(new EmployeeExport(EmployeeResource::collection($employees)), 'employees.xlsx');
        }

        // Print to PDF
        if ($request->boolean('print')) {
            $employees = $employeesQuery->get();
            return $this->pdf('print.employee.all', EmployeeResource::collection($employees), 'employees', 'landscape');
        }

        if ($request->filled('archived') && $request->archived == 'true') {
            // Paginated response
            $employeesQuery->with('terminationReason');
            return ArchivedEmployeeResource::collection($employeesQuery->paginate($request->per_page ?? 10));
        }

        return EmployeeResource::collection($employeesQuery->paginate($request->per_page ?? 10));
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


    /**
     * Store a newly created resource in storage.
     *
     * @param StoreEmployeeRequest $request
     *
     * @return EmployeeResource|JsonResponse
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
     *
     * @return EmployeeResource
     */
    public function show(string $employeeId): EmployeeResource
    {
        $employee = Employee::query()->where('uuid', $employeeId)->first();

        return new EmployeeResource($employee);
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
     * @param $id
     * @return EmployeeResource|JsonResponse
     */
    public function update(UpdateEmployeeRequest $request, $id): EmployeeResource|JsonResponse
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();

            $employee = Employee::findOrFail($id);
            $request['dob'] = $request->dob !== 'null' ? Carbon::parse($request->dob)->format('Y-m-d') : null;

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

            /*PreviousRank::updateOrCreate([
                'rank_id' => $employee->rank_id,
                'employee_id' => $employee->id
            ], [
                'rank_id' => $employee->rank_id,
                'employee_id' => $employee->id,
                'user_id' => Auth::id()
            ]);*/

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' updated the personal details for ' . $employee->name,
                'updated personal detail', [''], 'personal-details')
                ->to($employee)
                ->as($user);

            Helper::updateSRMS($request->staff_id);

            DB::commit();

            return new EmployeeResource($employee);
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
        return $minio->getFile($fileName);
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
}
