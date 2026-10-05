<?php

namespace App\Http\Middleware;

use App\Models\Config\Setting;
use Closure;
use Illuminate\Http\Request;

class RequireFeature
{
    public function handle(Request $request, Closure $next, string ...$features): mixed
    {
        // Each argument must be on; "a|b" is on when any of a, b is.
        $keys = array_map(fn($f) => "features.{$f}", array_merge(...array_map(fn($f) => explode('|', $f), $features)));

        $enabled = Setting::query()
            ->whereIn('key', $keys)
            ->pluck('value', 'key');

        foreach ($features as $feature) {
            $on = collect(explode('|', $feature))->contains(fn ($f) => (bool) ($enabled["features.{$f}"] ?? false));
            if (! $on) {
                return response()->json([
                    'message' => 'This feature is not available.',
                ], 403);
            }
        }

        return $next($request);
    }
}
