<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Audit trail for API actions: every request that changes data (POST/PUT/PATCH/DELETE), and every
 * export or download, is written to activity_log (log name "request") with who did it, what they
 * sent, the outcome and where it came from. Denied and failed attempts are recorded too.
 *
 * This complements model-level logging (RecordsActivity), which captures the old and new values,
 * and covers actions that change no model at all (emails sent, exports, bulk query updates).
 */
class RecordRequestActivity
{
    /** Input keys whose values are never stored. */
    private const SECRET_KEYS = '/pass(word)?|token|secret|otp|api[_-]?key/i';

    /** GET paths that hand data out of the system and are therefore audited. */
    private const AUDITED_READS = '#/(export|download|pdf|transcripts?|view)(/|$)#';

    private const MAX_VALUE_LENGTH = 1000;

    public function handle(Request $request, Closure $next): Response
    {
        // Read before the request runs: stop-impersonating clears it from the session.
        $impersonatorId = $request->hasSession() ? $request->session()->get('impersonating_original_id') : null;

        $response = $next($request);

        if ($this->shouldRecord($request)) {
            try {
                $this->record($request, $response, $impersonatorId);
            } catch (Throwable $e) {
                // Auditing must never break the request itself.
                Log::warning('Could not record request activity', ['exception' => $e]);
            }
        }

        return $response;
    }

    private function shouldRecord(Request $request): bool
    {
        if (!$request->isMethodSafe()) {
            return true;
        }

        return $request->boolean('export') || preg_match(self::AUDITED_READS, '/' . $request->path()) === 1;
    }

    private function record(Request $request, Response $response, mixed $impersonatorId): void
    {
        $route = $request->route();
        $method = $request->method();

        activity('request')
            ->causedBy($request->user())
            ->event(strtolower($method))
            ->withProperties([
                'method'          => $method,
                'path'            => '/' . $request->path(),
                'route'           => $route?->getName() ?? $route?->getActionName(),
                'status'          => $response->getStatusCode(),
                'input'           => $this->sanitize($request->input()),
                'files'           => $this->fileNames($request->allFiles()),
                'ip'              => $request->ip(),
                'user_agent'      => mb_substr((string) $request->userAgent(), 0, 255),
                'impersonator_id' => $impersonatorId,
            ])
            ->log("{$method} /{$request->path()}");
    }

    private function sanitize(array $input): array
    {
        $clean = [];

        foreach ($input as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEYS, $key)) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $clean[$key] = mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…';
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private function fileNames(array $files): array
    {
        return array_map(
            fn ($file) => $file instanceof UploadedFile ? $file->getClientOriginalName() : $this->fileNames((array) $file),
            $files,
        );
    }
}
