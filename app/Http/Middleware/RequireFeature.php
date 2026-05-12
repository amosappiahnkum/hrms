<?php

namespace App\Http\Middleware;

use App\Models\Config\Setting;
use Closure;
use Illuminate\Http\Request;

class RequireFeature
{
    public function handle(Request $request, Closure $next, string ...$features): mixed
    {
        $keys = array_map(fn($f) => "features.{$f}", $features);

        $enabled = Setting::query()
            ->whereIn('key', $keys)
            ->pluck('value', 'key');

        foreach ($features as $feature) {
            if (! (bool) ($enabled["features.{$feature}"] ?? false)) {
                return response()->json([
                    'message' => 'This feature is not available.',
                ], 403);
            }
        }

        return $next($request);
    }
}
