<?php

namespace App\Http\Controllers\Competency;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Competency\CompetencyEvidenceFile;
use App\Models\Competency\CompetencyRating;
use App\Models\Competency\DevelopmentAction;
use App\Models\SelfService\Employee;
use App\Services\Competency\CompetencyService;
use App\Services\MinioUploadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Evidence files on ratings (while their assessment is a draft) and on development actions (while
 * open, e.g. for the effectiveness check). Uploading and removing: those who may assess the employee.
 * Downloading: those who may see them. Files are private, served through short-lived URLs.
 */
class CompetencyEvidenceController extends Controller
{
    public function __construct(private readonly CompetencyService $service)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target'      => ['required', Rule::in(['rating', 'action'])],
            'target_uuid' => ['required', 'string'],
            'file'        => ['required', 'file', 'mimes:' . CompetencyEvidenceFile::MIMES, 'max:' . CompetencyEvidenceFile::MAX_KB],
        ], ['file.max' => 'Files can be up to 20 MB.']);

        $target = $data['target'] === 'rating'
            ? CompetencyRating::where('uuid', $data['target_uuid'])->with('assessment.employee')->firstOrFail()
            : DevelopmentAction::where('uuid', $data['target_uuid'])->with('employee')->firstOrFail();

        $this->ensureCanChange($request, $target);
        if ($target->evidenceFiles()->count() >= CompetencyEvidenceFile::MAX_PER_TARGET) {
            throw new UserFacingException('Up to ' . CompetencyEvidenceFile::MAX_PER_TARGET . ' files can be attached here.');
        }

        $file = $request->file('file');
        $uploaded = app(MinioUploadService::class)->upload($file, null, 'competency-evidence', 'private');

        $evidence = $target->evidenceFiles()->create([
            'file_path'   => $uploaded['path'],
            'file_name'   => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_size'   => $file->getSize(),
            'mime_type'   => $file->getMimeType() ?? 'application/octet-stream',
            'uploaded_by' => $request->user()->id,
        ]);

        return ApiResponse::success($evidence->load('uploader')->payload(), 'File attached.', 201);
    }

    /** A short-lived link to the file. */
    public function download(Request $request, CompetencyEvidenceFile $competencyEvidenceFile): JsonResponse
    {
        abort_unless($this->service->canSee($request->user(), $this->employeeOf($competencyEvidenceFile->evidenceable)), 403);

        return ApiResponse::success([
            'url' => Storage::disk('s3')->temporaryUrl($competencyEvidenceFile->file_path, now()->addMinutes(5)),
        ]);
    }

    /** Removed from the record; the stored file is kept (it may be carried forward elsewhere). */
    public function destroy(Request $request, CompetencyEvidenceFile $competencyEvidenceFile): JsonResponse
    {
        $this->ensureCanChange($request, $competencyEvidenceFile->evidenceable);
        $competencyEvidenceFile->delete();

        return ApiResponse::success(null, 'File removed.');
    }

    private function ensureCanChange(Request $request, ?Model $target): void
    {
        abort_unless($target && $this->service->canAssess($request->user(), $this->employeeOf($target)), 403, 'You cannot assess this employee.');

        $editable = $target instanceof CompetencyRating ? $target->assessment?->isDraft() : $target->status->isOpen();
        if (!$editable) {
            throw new UserFacingException($target instanceof CompetencyRating
                ? 'A completed assessment can\'t be changed. Start a new assessment to add evidence.'
                : 'A finished action can\'t be changed.');
        }
    }

    private function employeeOf(?Model $target): Employee
    {
        $employee = $target instanceof CompetencyRating ? $target->assessment?->employee : $target?->employee;
        abort_unless($employee, 404);

        return $employee;
    }
}
