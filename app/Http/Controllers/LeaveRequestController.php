<?php

namespace App\Http\Controllers;

use App\Exports\LeaveRequestExport;
use App\Helpers\LeaveHelper;
use App\Http\Requests\HrChangeLeaveStatusRequest;
use App\Http\Requests\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Resources\UpcomingLeaveResource;
use App\Models\ActivityLog;
use App\Models\Config\LeaveType;
use App\Models\Config\LeaveTypeLevelConfig;
use App\Models\LeaveBalanceAdjustment;
use App\Models\LeaveRequest;
use App\Models\SelfService\Employee;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\LeaveStatusNotification;
use App\Notifications\NotifyHodNotification;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class LeaveRequestController extends Controller
{
    private LeaveHelper $leaveHelper;

    public function __construct()
    {
        $this->middleware('auth');
        $this->leaveHelper = new LeaveHelper();
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return JsonResponse|AnonymousResourceCollection|BinaryFileResponse
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
     */
    public function index(Request $request)
    {
        if (!$this->isHrAdmin()) {
            return response()->json([
                'message' => 'You do not have permission to view leave requests.'
            ], 403);
        }

        $leaveRequestQuery = LeaveRequest::query()->forDepartment($request->department)
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('leaveType'), fn($q) => $q->where('leave_type_id', $request->leaveType))
            ->when($request->filled('search'), fn($q) => $q->searchEmployee($request->search))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'hod_approved' THEN 1 ELSE 2 END")
            ->latest();

        if ($request->boolean('export')) {
            $employees = $leaveRequestQuery->get();
            return Excel::download(new LeaveRequestExport($employees), 'leave-requests.xlsx');
        }

        return LeaveRequestResource::collection($leaveRequestQuery->paginate(10));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreLeaveRequestRequest $request
     *
     * @return JsonResponse
     */
    public function store(StoreLeaveRequestRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $employee = Auth::user()->employee;

            $hod = $employee->department->headOfDepartment;

            if (empty($hod)) {
                return response()->json([
                    'message' => 'No HOD assigned to your department.'
                ], 400);
            }


            $startDate = Carbon::parse($request->start_date)->format('Y-m-d');
            $daysRequested = (int) $request->number_of_days;

            $leaveType = LeaveType::where('uuid', $request->leave_type_id)->first();

            $activeRequest = LeaveRequest::where('employee_id', $employee->id)
                ->where('leave_type_id', $leaveType->id)
                ->whereIn('status', ['pending', 'hod_approved'])
                ->first();

            if ($activeRequest) {
                $statusLabel = match($activeRequest->status->value) {
                    'pending'      => 'awaiting HOD approval',
                    'hod_approved' => 'awaiting HR approval',
                    'hr_approved'  => 'already approved',
                    default        => $activeRequest->status->value,
                };
                return response()->json([
                    'message' => "You already have a {$leaveType->name} request that is {$statusLabel}. Please wait for it to be resolved before submitting a new one.",
                ], 422);
            }

            $uploadedFiles = $request->file('documents') ?? [];

            if ($leaveType->requires_document && empty($uploadedFiles)) {
                return response()->json([
                    'message' => "A supporting document is required for {$leaveType->name}.",
                ], 422);
            }

            if (!empty($uploadedFiles) && count($uploadedFiles) > $leaveType->max_documents) {
                return response()->json([
                    'message' => "You can upload a maximum of {$leaveType->max_documents} document(s) for {$leaveType->name}.",
                ], 422);
            }

            $this->leaveHelper->validateLeaveDays($startDate, $daysRequested, $leaveType->request_type);

            $balance = $this->calculateBalance($employee, $leaveType->id);
            if ($balance['available'] < $daysRequested) {
                return response()->json([
                    'message' => "Insufficient leave balance. You have {$balance['available']} {$leaveType->request_type}(s) available for {$leaveType->name}.",
                ], 422);
            }

            $relieverId = null;
            if ($request->filled('reliever_id')) {
                $reliever = Employee::where('uuid', $request->reliever_id)->first();
                $relieverId = $reliever?->id;
            }

            $leaveRequest = LeaveRequest::create([
                'employee_id' => $employee->id,
                'reliever_id' => $relieverId,
                'supervisor_id' => $hod->id,
                'department_id' => $employee->department->id,
                'leave_type_id' => $leaveType->id,
                'days_requested' => $daysRequested,
                'start_date' => $startDate,
                'reason' => $request->reason,
                'end_date' => $this->leaveHelper->lastDate,
            ]);

            foreach ($uploadedFiles as $file) {
                $path = $file->store("leave-documents/{$leaveRequest->uuid}", 's3');
                $leaveRequest->documents()->create([
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'mime_type' => $file->getMimeType(),
                ]);
            }

            $startDate = Carbon::parse($startDate)->format('l, M d Y');
            $endDate = Carbon::parse($this->leaveHelper->lastDate)->format('l, M d Y');

            $mailData = [
                'subject' => 'Time off Request',
                'greeting' => "Dear $hod->first_name!",
            ];

            if ($hod->id == $employee->id) { // hod is making request
                $leaveRequest->approvals()->create([
                    'approved_by' => $hod->userAccount->id,
                    'role' => 'hod',
                    'decision' => 'approved',
                    'comment' => "Automatically approved leave request.",
                    'decided_at' => Carbon::now(),
                    'days_approved' => $daysRequested,
                ]);
                $leaveRequest->update(['status' => 'hod_approved']);

                $mailData['lines'] = [
                    "Your $daysRequested day(s) leave request has been sent, pending HR decision."
                ];

                $hrMailData['lines'] = [
                    "Please note that $employee->name has submitted a $daysRequested day leave request, which is currently pending your action."
                ];
            } else {
                $mailData['lines'] = [
                    "$employee->name has requested $daysRequested day(s) off,",
                    "Starting from $startDate and will resume on $endDate",
                ];

                $hrMailData['lines'] = [
                    "Please note that $employee->name has submitted a $daysRequested day leave request, which is currently pending action from the Head of Department"
                ];
            }

            // notify employee about request
            Notification::route('mail', $hod->contactDetail->work_email)
                ->notify(new LeaveRequestNotification($mailData));

            $this->leaveHelper->notifyAllHrs($hrMailData);

            DB::commit();

            return response()->json(new LeaveRequestResource($leaveRequest));
        } catch (Exception $exception) {
            DB::rollBack();

            Log::error('Leave Request Error', [$exception]);

            return response()->json('Something went wrong', 400);
        }
    }


    /**
     * @return Collection
     */
    public function getHolidays(): Collection
    {
        return $this->leaveHelper->getHolidays();
    }

    /**
     * @return Collection
     */
    public function getLeaveTypes(): Collection
    {
        return LeaveType::all();
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws Throwable
     */
    public function changeLeaveStatus(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $leaveRequest = LeaveRequest::where('uuid', $request->id)->first();

            $hodDepartmentId = Auth::user()->employee->department_id;
            if ($leaveRequest->department_id !== $hodDepartmentId) {
                return response()->json([
                    'message' => 'You can only approve leave requests for employees in your department.',
                ], 403);
            }

            $requestType = $leaveRequest->leaveType->request_type;
            $date = Carbon::now()->format('Y-m-d');
            $daysApproved =
                $request->days_requested != $leaveRequest->days_requested ?
                    $this->leaveHelper->validateLeaveDays($request->start_date, $request->days_requested, $requestType)
                    : $request->days_requested;

            $decision = $request->decision;

            if ($decision === 'approved' && $leaveRequest->leaveType->requires_document && $leaveRequest->documents->isEmpty()) {
                return response()->json(['message' => 'Supporting documents are required before this leave can be approved.'], 422);
            }

            $daysApproved = $decision == 'approved' ? $daysApproved : 0;
            $leaveRequest->update([
                'days_approved' => $daysApproved,
                'start_date' => Carbon::parse($request->start_date)->format('Y-m-d'),
                'status' => $request->status,
                'sup_reason' => $request->sup_reason,
                'end_date' => $this->leaveHelper->lastDate ?: $leaveRequest->end_date,
                'sup_approval' => $date,
                'viewed' => true,
            ]);

            $hod = Auth::user();

            $leaveRequest->approvals()->create([
                'approved_by' => $hod->id,
                'role' => 'hod',
                'decision' => $decision,
                'comment' => $request->sup_reason,
                'decided_at' => Carbon::now(),
                'days_approved' => $daysApproved,
            ]);

            $employeeUserAccount = $leaveRequest->employee->userAccount;

            $hodName = $hod->employee->name;

            // notify supervisor
            $hod->notify(new NotifyHodNotification([
                'leaveStatus' => $decision,
                'supervisor' => $hodName,
                'employee' => $employeeUserAccount->employee->name,
                'date' => $date
            ]));

            $emp = $employeeUserAccount->employee->name;

            $mailData = [
                'subject' => "Leave request $decision",
                'greeting' => "Dear $emp!",
            ];

            if ($decision == 'approved') {
                $mailData['lines'] = [
                    "We are pleased to inform you that your leave request has been $decision by $hodName,pending HR decision.",
                    "If you have any questions or require further clarification, please do not hesitate to reach out."
                ];

                // notify HRs
                $hrMailData['lines'] = [
                    "Please note that $hodName $decision a $daysApproved $requestType leave request for $emp, which is currently pending your action"
                ];

                $this->leaveHelper->notifyAllHrs($hrMailData);

            } else {
                $mailData['lines'] = [
                    "We regret to inform you that your leave request has been reviewed and has not been approved.",
                    "If you have any questions or would like to discuss the decision further, please feel free to reach out."
                ];
            }

            // notify employee
            $employeeUserAccount->notify(new LeaveStatusNotification($mailData));

            ActivityLog::add($hod->name . ' ' . $decision . ' ' . $daysApproved .
                ' day(s) leave request starting from ' . $request->start_date . ' to ' . $this->leaveHelper->lastDate,
                $decision, [''], 'leave-request')
                ->to($leaveRequest)
                ->as($hod);

            DB::commit();

            return response()->json(new LeaveRequestResource($leaveRequest));
        } catch (Exception $exception) {
            DB::rollBack();
            Log::error('Change Leave Status: ', [$exception]);

            return response()->json('Something went wrong', 400);
        }
    }

    /**
     * @param HrChangeLeaveStatusRequest $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function hrChangeLeaveStatus(HrChangeLeaveStatusRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $user = Auth::user();

            $leaveRequest = LeaveRequest::where('uuid', $request->id)->first();

            $daysApproved = $this->leaveHelper->validateLeaveDays($request->start_date, $request->days_requested, $leaveRequest->leaveType->request_type);

            $decision = $request->decision;

            if ($decision === 'approved' && $leaveRequest->leaveType->requires_document && $leaveRequest->documents->isEmpty()) {
                return response()->json(['message' => 'Supporting documents are required before this leave can be approved.'], 422);
            }

            $daysApproved = $decision == 'approved' ? $daysApproved : 0;

            $start = Carbon::parse($request->start_date);
            $end = Carbon::parse($this->leaveHelper->lastDate);
            $leaveRequest->update([
                'days_approved' => $daysApproved,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'hr_reason' => $request->hr_reason,
                'hr_status' => $decision,
                'status' => $request->status,
                'hr_approval' => date('Y-m-d'),
                'hr_id' => $user?->employee->id
            ]);

            $leaveRequest->approvals()->create([
                'approved_by' => $user->id,
                'role' => 'hr',
                'decision' => $decision,
                'comment' => $request->sup_reason,
                'decided_at' => Carbon::now(),
                'days_approved' => $daysApproved,
            ]);

            ActivityLog::add($user->employee->name . ' ' . $decision . ' leave request', $decision, [''], 'leave-request')
                ->to($leaveRequest)
                ->as($user);

            $employeeUserAccount = $leaveRequest->employee->userAccount;

            $employee = $employeeUserAccount->employee->first_name;
            $hr = $user->employee->first_name;

            $mailData = [
                'subject' => "Leave Request $decision",
                'greeting' => "Dear $employee!",
            ];

            if ($decision == 'approved') {
                $start = $start->format('F d, Y');
                $end = $end->format('F d, Y');
                $mailData['lines'] = [
                    "We are pleased to inform you that your leave request has been $decision by $hr.",
                    "Your approved leave period will commence on $start, and conclude on $end.",
                    "If you have any questions or require further clarification, please do not hesitate to reach out."
                ];
            } else {
                $mailData['lines'] = [
                    "We regret to inform you that your leave request has been reviewed and has not been approved.",
                    "If you have any questions or would like to discuss the decision further, please feel free to reach out."
                ];
            }

            $mailData['cc'] = User::role('hr')->get()->pluck('email');

            $employeeUserAccount->notify(new LeaveStatusNotification($mailData));

            DB::commit();

            return response()->json(new LeaveRequestResource($leaveRequest));
        } catch (Exception $exception) {
            DB::rollBack();
            Log::error('HR Change Leave Status: ', [$exception]);

            return response()->json('Something went wrong', 400);
        }
    }

    /**
     * Cancel a leave request. Employees may cancel pending/hod_approved;
     * HODs may cancel pending/hod_approved in their department;
     * HR may cancel any leave regardless of status.
     */
    public function cancelLeave(string $uuid): JsonResponse
    {
        $user = Auth::user();
        $employee = $user->employee;

        $leaveRequest = LeaveRequest::where('uuid', $uuid)->firstOrFail();

        if ($leaveRequest->status->value === 'canceled') {
            return response()->json(['message' => 'This leave request is already cancelled.'], 422);
        }

        $cancellableStatuses = ['pending', 'hod_approved'];

        if ($this->isHrAdmin()) {
            // HR can cancel any status
        } elseif ($this->isSupervisor() && $leaveRequest->department_id === $employee->department_id) {
            if (!in_array($leaveRequest->status->value, $cancellableStatuses)) {
                return response()->json(['message' => 'Only pending or HOD-approved leaves can be cancelled.'], 422);
            }
        } elseif ($leaveRequest->employee_id === $employee->id) {
            if (!in_array($leaveRequest->status->value, $cancellableStatuses)) {
                return response()->json(['message' => 'Only pending or HOD-approved leaves can be cancelled.'], 422);
            }
        } else {
            return response()->json(['message' => 'You do not have permission to cancel this leave request.'], 403);
        }

        $leaveRequest->update(['status' => 'canceled']);

        return response()->json(new LeaveRequestResource($leaveRequest));
    }

    public function addDocuments(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'documents'   => 'required|array',
            'documents.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        $leaveRequest = LeaveRequest::where('uuid', $uuid)->firstOrFail();
        $employee = Auth::user()->employee;

        if ($employee->id !== $leaveRequest->employee_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $currentCount = $leaveRequest->documents->count();
        $maxDocuments = $leaveRequest->leaveType->max_documents;
        $newFiles = $request->file('documents');

        if ($currentCount + count($newFiles) > $maxDocuments) {
            return response()->json([
                'message' => "You can upload a maximum of {$maxDocuments} document(s). You already have {$currentCount}.",
            ], 422);
        }

        foreach ($newFiles as $file) {
            $path = $file->store("leave-documents/{$leaveRequest->uuid}", 's3');
            $leaveRequest->documents()->create([
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
            ]);
        }

        return response()->json(new LeaveRequestResource($leaveRequest->fresh()));
    }

    public function removeDocument(string $uuid, int $documentId): JsonResponse
    {
        $leaveRequest = LeaveRequest::where('uuid', $uuid)->firstOrFail();
        $document = $leaveRequest->documents()->findOrFail($documentId);

        Storage::disk('s3')->delete($document->file_path);
        $document->delete();

        return response()->json(new LeaveRequestResource($leaveRequest->fresh()));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param LeaveRequest $leaveRequest
     *
     * @return Response
     */
    public function destroy(LeaveRequest $leaveRequest)
    {
        //
    }

    /**
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        $leaveRequest = LeaveRequest::where('uuid', $id)->firstOrFail();

        return response()->json(new LeaveRequestResource($leaveRequest));
    }

    /**
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function getMyLeaveRequest(Request $request): AnonymousResourceCollection
    {
        $auth = Auth::user();

        $leaveRequest = LeaveRequest::query()
            ->where('employee_id', $auth->employee->id)
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'hod_approved' THEN 1 ELSE 2 END")
            ->latest();

        return LeaveRequestResource::collection($leaveRequest->paginate($request->per_page ?? 10));
    }

    public function getMyLeaveStats(Request $request): JsonResponse
    {
        $employeeId = Auth::user()->employee_id;

        $counts = LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'total' => $counts->sum(),
            'pending' => $counts->get('pending', 0),
            'approved' => $counts->get('supervisor_approved', 0) + $counts->get('hr_approved', 0),
            'rejected' => $counts->get('supervisor_rejected', 0) + $counts->get('hr_rejected', 0)
        ]);
    }

    public function getMyLeaveBalance(Request $request): JsonResponse
    {
        $employee = Auth::user()->employee;

        $balances = LeaveTypeLevelConfig::with('leaveType')
            ->where('job_category_id', $employee->jobDetail->job_category_id)
            ->get()
            ->map(function ($config) use ($employee) {
                return $this->calculateBalance($employee, $config->leave_type_id, $config);
            });

        return response()->json($balances);
    }

    private function calculateBalance(Employee $employee, int $leaveTypeId, ?LeaveTypeLevelConfig $config = null): array
    {
        if (!$config) {
            $config = LeaveTypeLevelConfig::with('leaveType')
                ->where('job_category_id', $employee->jobDetail->job_category_id)
                ->where('leave_type_id', $leaveTypeId)
                ->first();
        }

        $adjustment = LeaveBalanceAdjustment::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveTypeId)
            ->sum('days');

        $total = $config->number_of_days + $adjustment;

        $used = LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', 'hr_approved')
            ->sum('days_approved');

        $pending = LeaveRequest::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveTypeId)
            ->whereIn('status', ['pending', 'hod_approved'])
            ->sum('days_requested');

        return [
            'leave_type_id' => $leaveTypeId,
            'type' => $config->leaveType->name,
            'total' => $total,
            'used' => $used,
            'pending' => $pending,
            'remaining' => max(0, $total - $used),
            'available' => max(0, $total - $used - $pending),
        ];
    }

    public function getUpcomingLeave(): AnonymousResourceCollection
    {
        $departmentId = Auth::user()->employee->department_id;
        $upcomingLeaves = LeaveRequest::with([
            'employee:id,uuid,first_name,middle_name,last_name,department_id,title,staff_id',
            'leaveType:id,name'
        ])->whereIn('status', [
            'hr_approved'
        ])
            ->whereDate('start_date', '>=', Carbon::today())
            ->whereDate('end_date', '<=', Carbon::today())
            ->whereHas('employee', function ($query) use ($departmentId) {
                $query->where('department_id', $departmentId);
            })
            ->orderBy('start_date')
            ->get();

        return UpcomingLeaveResource::collection($upcomingLeaves);
    }


    public function getTeamLeaveRequest(Request $request): JsonResponse|AnonymousResourceCollection
    {
        if (!$this->isSupervisor()) {
            return response()->json([
                'message' => 'You do not have permission to view leave requests.'
            ], 403);
        }

        $departmentId = auth()->user()->employee->department_id;

        $upcomingLeaves = LeaveRequest::query()->with([
            'employee:id,uuid,first_name,middle_name,last_name,department_id,title,staff_id',
            'leaveType:id,name',
            'resumption',
        ])->forDepartment($departmentId)
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('resumption_status'), fn($q) => $q->whereHas('resumption', fn($r) => $r->where('status', $request->resumption_status)))
            ->when($request->filled('search'), fn($q) => $q->searchEmployee($request->search))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'hod_approved' THEN 1 ELSE 2 END")
            ->latest()
            ->paginate(10);

        return LeaveRequestResource::collection($upcomingLeaves);
    }
}
