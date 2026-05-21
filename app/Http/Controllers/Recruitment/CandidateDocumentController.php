<?php

namespace App\Http\Controllers\Recruitment;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\CandidateDocument;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CandidateDocumentController extends Controller
{
    protected function candidateFromAuth(): Candidate
    {
        return Auth::guard('candidate')->user();
    }

    public function index(Request $request): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        $documents = $candidate->documents()->latest()->get();

        return ApiResponse::success($documents->map(fn($d) => [
            'uuid'         => $d->uuid,
            'type'         => $d->type,
            'display_name' => $d->display_name,
            'path'         => $d->path,
            'mime_type'    => $d->mime_type,
            'size'         => $d->size,
            'created_at'   => $d->created_at,
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file'         => ['required', 'file', 'max:20480'],
            'type'         => ['required', 'string', Rule::in(['certificate', 'transcript', 'cv', 'portfolio', 'other'])],
            'display_name' => ['required', 'string', 'max:255'],
        ]);

        $candidate = $this->candidateFromAuth();

        try {
            $file = $request->file('file');

            $directory = 'candidates/docs/' . $candidate->uuid;
            $path      = $file->store($directory, 'public');

            $document = $candidate->documents()->create([
                'type'         => $request->type,
                'display_name' => $request->display_name,
                'path'         => $path,
                'mime_type'    => $file->getMimeType(),
                'size'         => $file->getSize(),
            ]);

            return ApiResponse::success([
                'uuid'         => $document->uuid,
                'type'         => $document->type,
                'display_name' => $document->display_name,
                'path'         => $document->path,
                'mime_type'    => $document->mime_type,
                'size'         => $document->size,
                'created_at'   => $document->created_at,
            ], 'Document uploaded', 201);
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }

    public function show(CandidateDocument $document): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($document->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this document.');
        }

        return ApiResponse::success([
            'uuid'         => $document->uuid,
            'type'         => $document->type,
            'display_name' => $document->display_name,
            'path'         => $document->path,
            'mime_type'    => $document->mime_type,
            'size'         => $document->size,
            'created_at'   => $document->created_at,
        ]);
    }

    public function destroy(CandidateDocument $document): JsonResponse
    {
        $candidate = $this->candidateFromAuth();

        if ($document->candidate_id !== $candidate->id) {
            abort(403, 'You do not have access to this document.');
        }

        try {
            Storage::disk('public')->delete($document->path);
            $document->delete();

            return ApiResponse::success(null, 'Document deleted');
        } catch (Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 400);
        }
    }
}
