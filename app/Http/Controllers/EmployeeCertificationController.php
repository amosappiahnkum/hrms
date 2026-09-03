<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\EmployeeCertificationResource;
use App\Models\CertificationProvider;
use App\Models\EmployeeCertification;
use App\Models\SelfService\Employee;
use App\Notifications\CertificationActionNotification;
use App\Services\MinioUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class EmployeeCertificationController extends Controller
{
    // ── HR / Admin ──────────────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = EmployeeCertification::with(['provider', 'employee', 'uploader'])
            ->when($request->employee_id, fn($q, $v) => $q->whereHas(
                'employee', fn($eq) => $eq->where('uuid', $v)
            ))
            ->when($request->provider_id, fn($q, $v) => $q->where('certification_provider_id', $v))
            ->when($request->status === 'expired', fn($q) => $q->expired())
            ->when($request->status === 'expiring_soon', fn($q) => $q->expiringSoon())
            ->when($request->search, fn($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return EmployeeCertificationResource::collection($query);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->isHrAdmin(), 403, 'Unauthorized.');

        $data = $request->validate([
            'employee_uuid'            => ['required', 'string', 'exists:employees,uuid'],
            'title'                    => ['required', 'string', 'max:255'],
            'description'              => ['nullable', 'string'],
            'date_received'            => ['required', 'date'],
            'expiry_date'              => ['nullable', 'date', 'after:date_received', 'required_if:does_not_expire,false'],
            'does_not_expire'          => ['boolean'],
            'certification_provider_id' => ['nullable', 'exists:certification_providers,id'],
            'provider_name'            => ['nullable', 'string', 'max:255', 'required_without:certification_provider_id'],
            'file'                     => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:20480'],
        ]);

        $providerId = $this->resolveProvider($request);
        $employee   = Employee::where('uuid', $request->employee_uuid)->firstOrFail();
        $uploaded   = app(MinioUploadService::class)->upload($request->file('file'), null, 'employee-certifications');

        $cert = EmployeeCertification::create([
            'employee_id'               => $employee->id,
            'certification_provider_id' => $providerId,
            'title'                     => $request->title,
            'description'               => $request->description,
            'date_received'             => $request->date_received,
            'expiry_date'               => $request->boolean('does_not_expire') ? null : $request->expiry_date,
            'does_not_expire'           => $request->boolean('does_not_expire', false),
            'file_path'                 => $uploaded['path'],
            'file_name'                 => $request->file('file')->getClientOriginalName(),
            'file_size'                 => $request->file('file')->getSize(),
            'mime_type'                 => $request->file('file')->getMimeType(),
            'uploaded_by'               => auth()->id(),
        ]);

        activity('certifications')->performedOn($cert)->log("Uploaded certification: {$cert->title} for employee #{$employee->staff_id}");

        $cert->load(['provider', 'employee', 'uploader']);
        $this->notifyEmployee($cert, 'uploaded');

        return ApiResponse::success(
            EmployeeCertificationResource::make($cert),
            'Certification uploaded.',
            201
        );
    }

    public function show(EmployeeCertification $employeeCertification): JsonResponse
    {
        abort_unless($this->isHrAdmin(), 403, 'Unauthorized.');

        return ApiResponse::success(
            EmployeeCertificationResource::make($employeeCertification->load(['provider', 'employee', 'uploader']))
        );
    }

    public function update(Request $request, EmployeeCertification $employeeCertification): JsonResponse
    {
        abort_unless($this->isHrAdmin(), 403, 'Unauthorized.');

        $request->validate([
            'title'                    => ['sometimes', 'string', 'max:255'],
            'description'              => ['nullable', 'string'],
            'date_received'            => ['sometimes', 'date'],
            'expiry_date'              => ['nullable', 'date'],
            'does_not_expire'          => ['boolean'],
            'certification_provider_id' => ['nullable', 'exists:certification_providers,id'],
            'provider_name'            => ['nullable', 'string', 'max:255'],
        ]);

        $providerId = $request->filled('provider_name')
            ? $this->resolveProvider($request)
            : $request->input('certification_provider_id', $employeeCertification->certification_provider_id);

        $doesNotExpire = $request->boolean('does_not_expire', $employeeCertification->does_not_expire);

        $employeeCertification->update([
            'certification_provider_id' => $providerId,
            'title'                     => $request->input('title', $employeeCertification->title),
            'description'               => $request->input('description', $employeeCertification->description),
            'date_received'             => $request->input('date_received', $employeeCertification->date_received),
            'expiry_date'               => $doesNotExpire ? null : $request->input('expiry_date', $employeeCertification->expiry_date),
            'does_not_expire'           => $doesNotExpire,
        ]);

        activity('certifications')->performedOn($employeeCertification)->log("Updated certification: {$employeeCertification->title}");

        $employeeCertification->load(['provider', 'employee', 'uploader']);
        $this->notifyEmployee($employeeCertification, 'updated');

        return ApiResponse::success(
            EmployeeCertificationResource::make($employeeCertification)
        );
    }

    public function destroy(EmployeeCertification $employeeCertification): JsonResponse
    {
        abort_unless($this->isHrAdmin(), 403, 'Unauthorized.');

        $employeeCertification->loadMissing(['provider', 'employee.userAccount']);

        app(MinioUploadService::class)->delete($employeeCertification->file_path);

        $title = $employeeCertification->title;
        $this->notifyEmployee($employeeCertification, 'deleted');

        $employeeCertification->delete();

        activity('certifications')->log("Deleted certification: {$title}");

        return ApiResponse::success([], 'Certification deleted.');
    }

    /** Returns a short-lived signed URL so HR can download the file directly. */
    public function download(EmployeeCertification $employeeCertification): JsonResponse
    {
        abort_unless($this->isHrAdmin(), 403, 'Unauthorized.');

        $url = Storage::disk('s3')->temporaryUrl(
            $employeeCertification->file_path,
            now()->addMinutes(5)
        );

        return ApiResponse::success(['url' => $url]);
    }

    // ── Employee self-service ───────────────────────────────────────────────────

    /** Employee's own certifications — metadata only, no file access. */
    public function myCertifications(Request $request): AnonymousResourceCollection
    {
        $employee = auth()->user()->employee;

        abort_unless($employee, 403);

        $certs = EmployeeCertification::with('provider')
            ->where('employee_id', $employee->id)
            ->when($request->search, fn($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return EmployeeCertificationResource::collection($certs);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    private function notifyEmployee(EmployeeCertification $cert, string $action): void
    {
        $userAccount = $cert->employee?->userAccount;

        if (!$userAccount) {
            return;
        }

        $userAccount->notify(new CertificationActionNotification(
            action:           $action,
            title:            $cert->title,
            providerName:     $cert->provider?->name ?? 'Unknown',
            employeeFirstName: $cert->employee->first_name,
            dateReceived:     $cert->date_received?->format('d M Y'),
            expiryDate:       $cert->expiry_date?->format('d M Y'),
            doesNotExpire:    $cert->does_not_expire,
        ));
    }

    private function resolveProvider(Request $request): int
    {
        if ($request->filled('certification_provider_id')) {
            return (int) $request->certification_provider_id;
        }

        return CertificationProvider::firstOrCreate(
            ['name' => trim($request->provider_name)]
        )->id;
    }
}
