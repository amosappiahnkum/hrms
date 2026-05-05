<?php

namespace App\Services;

use App\Helpers\Helper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MinioUploadService
{
    protected string $disk = 's3';

    /**
     * Upload file to MinIO
     */
    public function upload(UploadedFile $file, string $filename = null, string $folder = 'uploads'): array
    {
        try {
            $name = $this->generateFileName($file, $filename);

            $path = Storage::disk($this->disk)->putFileAs(
                $folder,
                $file,
                $name,
                'public'
            );

            return [
                'path' => $path,
                'url' => Helper::getPhotoURL($name),
//                'url' => Storage::disk($this->disk)->url($path),
                'filename' => $name,
            ];
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            throw $exception;
        }
    }


    public function getFile(string $fileName): ?string
    {
        /*try {
            return Storage::disk('s3')->get("photos/{$fileName}");
        }catch (\Exception $exception) {
            Log::error('getfile', [$exception]);

            return null;
        }*/

        try {
            $file = Storage::disk('s3')->get("photos/{$fileName}");

            return response($file, 200)
                ->header('Content-Type', Storage::disk('s3')->mimeType("photos/{$fileName}"))
                ->header('Cache-Control', 'public, max-age=31536000');

        } catch (\Exception $exception) {
            Log::error('getfile', [$exception]);

            abort(404);
        }
    }

    /**
     * Delete file from MinIO
     */
    public function delete(string $path): bool
    {
        return Storage::disk($this->disk)->delete($path);
    }

    /**
     * Generate unique filename
     */
    protected function generateFileName(UploadedFile $file, ?string $filename = null): string
    {
        $fileName = $filename ?? Str::uuid();

        return $fileName . '.' . $file->getClientOriginalExtension();
    }
}
