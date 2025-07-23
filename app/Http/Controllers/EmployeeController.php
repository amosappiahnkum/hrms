<?php

namespace App\Http\Controllers;

use App\Exports\EmployeeExport;
use App\Helpers\SaveFile;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\ActivityLog;
use App\Models\ContactDetail;
use App\Models\Employee;
use App\Models\PreviousRank;
use App\Traits\InformationUpdate;
use App\Traits\UsePrint;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends Controller
{
    use UsePrint, InformationUpdate;

    protected string $docPath = 'images/employees';
    protected array $allowedFiles = ['png', 'jpg', 'jpeg'];

    /**
     * Display a listing of the resource.
     *
     * @return AnonymousResourceCollection|\Illuminate\Http\Response|BinaryFileResponse
     */
    public function index(Request $request)
    {
        $employeesQuery = Employee::query();
        $employeesQuery->when($request->has('department_id') &&
            $request->department_id !== 'all', function ($q) use ($request) {
            return $q->where('department_id', $request->department_id);
        });

        $employeesQuery->when($request->has('rank_id') &&
            $request->rank_id !== 'all', function ($q) use ($request) {
            return $q->where('rank_id', $request->rank_id);
        });

        $employeesQuery->when($request->has('job_category_id') &&
            $request->job_category_id !== 'all', function ($q) use ($request) {
            return $q->whereRelation('jobDetail', static function ($jQuery) use ($request) {
                return $jQuery->where('job_category_id', $request->job_category_id);
            });
        });


        if ($request->has('export') && $request->export === 'true') {
            return Excel::download(new EmployeeExport(EmployeeResource::collection($employeesQuery->get())),
                'Expenses.xlsx');
        }

        if ($request->has('print') && $request->print === 'true') {
            return $this->pdf('print.employee.all', EmployeeResource::collection($employeesQuery->get()), 'Expenses',
                'landscape');
        }

        return EmployeeResource::collection($employeesQuery->paginate(10));
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
     * @param Employee $employee
     *
     * @return EmployeeResource
     */
    public function show(Employee $employee): EmployeeResource
    {
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

    public function searchEmployees($query): AnonymousResourceCollection
    {
        $employees = Employee::query()
            ->where('last_name', 'like', '%' . $query . '%')
            ->orWhere('middle_name', 'like', '%' . $query . '%')
            ->orWhere('first_name', 'like', '%' . $query . '%')->get();

        return EmployeeResource::collection($employees);
    }

    public function getPeople(): AnonymousResourceCollection
    {
        $employeesQuery = Employee::query();

        if (!$this->getRoles()?->contains('super-admin')) {
            $employeesQuery->where('department_id', Auth::user()->employee->department_id);
        }

        return EmployeeResource::collection($employeesQuery->paginate(10));
    }

    public function getStaff(Request $request)
    {
        $employee = Employee::query()->with('contactDetail')->where('staff_id', $request->staffId)->first();

        if (!$employee) {
            return response()->json([
                'message' => 'Employee not found'
            ], 400);
        }

        return response()->json($employee);
    }

    public function updateStaffMail(Request $request)
    {
        ContactDetail::find($request->id)->update($request->only(['work_email']));

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
            $employee = Employee::findOrFail($id);
            $request['dob'] = $request->dob !== 'null' ? Carbon::parse($request->dob)->format('Y-m-d') : null;

            $this->infoDifference($employee, $request->all());
            $this->requestUpdate($employee);

            if ($request->has('file') && $request->file !== "null") {
                $saveFile = new SaveFile($employee, $request->file('file'), $this->docPath, $this->allowedFiles);
                $saveFile->save();
            }

            PreviousRank::updateOrCreate([
                'rank_id' => $employee->rank_id,
                'employee_id' => $employee->id
            ], [
                'rank_id' => $employee->rank_id,
                'employee_id' => $employee->id,
                'user_id' => Auth::id()
            ]);

            $user = Auth::user();

            ActivityLog::add(($user?->employee?->name ?? $user->username) . ' updated the personal details for ' . $employee->name,
                'updated', [''], 'job-details')
                ->to($employee)
                ->as($user);

            DB::commit();

            return new EmployeeResource($employee);
        } catch (Exception $exception) {

            Log::info('Employee update failed', [$exception]);
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }
}
