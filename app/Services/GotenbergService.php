<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GotenbergService
{
    /**
     * Convert a document to PDF via Gotenberg and store the result in S3.
     *
     * @param  string  $fileContent  Raw file bytes
     * @param  string  $fileName     Original filename (Gotenberg uses the extension to pick the converter)
     * @param  string  $originalPath S3 key of the source file, used to derive the preview path
     * @return string  S3 key of the stored PDF
     *
     * @throws \RuntimeException if Gotenberg returns a non-2xx response
     */
    public function convertToPdf(string $fileContent, string $fileName, string $originalPath): string
    {
        $response = Http::timeout(120)
            ->attach('file', $fileContent, $fileName)
            ->post(config('services.gotenberg.url') . '/forms/libreoffice/convert');

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Gotenberg conversion failed (HTTP {$response->status()}): {$response->body()}"
            );
        }

        $previewPath = $this->previewPath($originalPath);

        Storage::disk('s3')->put($previewPath, $response->body());

        return $previewPath;
    }

    /** Derive the S3 preview key from the original file key. */
    public function previewPath(string $originalPath): string
    {
        $dir      = pathinfo($originalPath, PATHINFO_DIRNAME);
        $baseName = pathinfo($originalPath, PATHINFO_FILENAME);

        return "{$dir}/previews/{$baseName}.pdf";
    }
}
