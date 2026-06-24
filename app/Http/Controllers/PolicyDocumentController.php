<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\PolicyDocumentResource;
use App\Models\PolicyDocument;
use App\Services\GotenbergService;
use App\Services\MinioUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyDocumentController extends Controller
{
    // ── Admin: full CRUD ──────────────────────────────────────────────────────

    public function index(Request $request): AnonymousResourceCollection
    {
        $docs = PolicyDocument::with('uploader')
            ->when($request->category, fn($q, $v) => $q->where('category', $v))
            ->when($request->scope_type, fn($q, $v) => $q->where('scope_type', $v))
            ->when($request->filled('is_active'), fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->search, fn($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return PolicyDocumentResource::collection($docs);
    }

    /**
     * @throws \Exception
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'in:policy,manual,handbook,notice,form,other'],
            'file' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx', 'max:51200'],
            'is_downloadable' => ['boolean'],
            'scope_type' => ['required', 'in:all,department,job_category,role'],
            'scope_ids' => ['nullable', 'array'],
            'scope_ids.*' => ['required'],
            'is_active' => ['boolean'],
        ]);

        $file = $request->file('file');
        $minio = app(MinioUploadService::class);
        $uploaded = $minio->upload($file, null, 'policy-documents');

        $previewPath = null;
        if (!str_contains($file->getMimeType() ?? '', 'pdf')) {
            try {
                $previewPath = app(GotenbergService::class)->convertToPdf(
                    $file->get(),
                    $file->getClientOriginalName(),
                    $uploaded['path'],
                );
            } catch (\Throwable $e) {
                Log::warning('Gotenberg conversion failed for ' . $file->getClientOriginalName() . ': ' . $e->getMessage());
            }
        }

        $doc = PolicyDocument::create([
            'title'           => $request->title,
            'description'     => $request->description,
            'category'        => $request->category,
            'file_path'       => $uploaded['path'],
            'preview_path'    => $previewPath,
            'file_name'       => $file->getClientOriginalName(),
            'file_size'       => $file->getSize(),
            'mime_type'       => $file->getMimeType(),
            'is_downloadable' => $request->boolean('is_downloadable', true),
            'scope_type'      => $request->scope_type,
            'scope_ids'       => $request->scope_type === 'all' ? null : $request->scope_ids,
            'is_active'       => $request->boolean('is_active', true),
            'user_id'         => auth()->id(),
        ]);

        return ApiResponse::success(PolicyDocumentResource::make($doc), 'Document uploaded.', 201);
    }

    public function update(Request $request, PolicyDocument $policyDocument): JsonResponse
    {
        $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['sometimes', 'in:policy,manual,handbook,notice,form,other'],
            'is_downloadable' => ['boolean'],
            'scope_type' => ['sometimes', 'in:all,department,job_category,role'],
            'scope_ids' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $policyDocument->update([
            ...$request->only(['title', 'description', 'category', 'is_downloadable', 'is_active']),
            'scope_type' => $request->input('scope_type', $policyDocument->scope_type),
            'scope_ids' => $request->input('scope_type', $policyDocument->scope_type) === 'all'
                ? null
                : $request->input('scope_ids', $policyDocument->scope_ids),
        ]);

        return ApiResponse::success(PolicyDocumentResource::make($policyDocument));
    }

    public function destroy(PolicyDocument $policyDocument): JsonResponse
    {
        $minio = app(MinioUploadService::class);
        $minio->delete($policyDocument->file_path);

        if ($policyDocument->preview_path) {
            $minio->delete($policyDocument->preview_path);
        }

        $policyDocument->delete();

        return ApiResponse::success([], 'Document deleted.');
    }

    /** Admin: stream the file through Laravel (inline or download). */
    public function view(Request $request, PolicyDocument $policyDocument): StreamedResponse
    {
        return $this->streamDocument($policyDocument, $request->boolean('download'));
    }

    // ── Employee self-service ─────────────────────────────────────────────────

    /** Documents scoped to the authenticated employee. */
    public function myDocuments(Request $request): AnonymousResourceCollection
    {
        $employee = auth()->user()->employee;

        if (!$employee) {
            return PolicyDocumentResource::collection(collect());
        }

        // Eager-load jobDetail so the scope works without N+1
        $employee->loadMissing(['jobDetail', 'department']);

        $docs = PolicyDocument::accessibleBy($employee)
            ->when($request->category, fn($q, $v) => $q->where('category', $v))
            ->when($request->search, fn($q, $v) => $q->where('title', 'like', "%{$v}%"))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return PolicyDocumentResource::collection($docs);
    }

    /** Employee: stream the file through Laravel (download only if is_downloadable). */
    public function myView(Request $request, PolicyDocument $policyDocument): StreamedResponse
    {
        $employee = auth()->user()->employee;

        abort_unless($employee && $policyDocument->isAccessibleBy($employee), 403);

        $forDownload = $request->boolean('download') && $policyDocument->is_downloadable;

        return $this->streamDocument($policyDocument, $forDownload);
    }

    private function streamDocument(PolicyDocument $policyDocument, bool $forDownload = false): StreamedResponse
    {
        // For viewing, prefer the Gotenberg-converted PDF when available
        $usePreview = !$forDownload && $policyDocument->preview_path;
        $path       = $usePreview ? $policyDocument->preview_path : $policyDocument->file_path;
        $mimeType   = $usePreview ? 'application/pdf' : $policyDocument->mime_type;

        $stream = Storage::disk('s3')->readStream($path);

        abort_if(!$stream, 404);

        $disposition = $forDownload
            ? 'attachment; filename="' . addslashes($policyDocument->file_name) . '"'
            : 'inline';

        return response()->stream(
            fn() => fpassthru($stream),
            200,
            [
                'Content-Type'           => $mimeType,
                'Content-Disposition'    => $disposition,
                'Cache-Control'          => 'no-store, no-cache, must-revalidate',
                'Pragma'                 => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options'        => 'SAMEORIGIN',
            ]
        );
    }
}
