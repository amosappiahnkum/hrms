<?php

namespace App\Http\Middleware;

use App\Services\Competency\CompetencyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The competency matrix is for competency permission holders and people who lead a team. */
class CompetencyAccess
{
    public function __construct(private readonly CompetencyService $service)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && $this->service->hasAccess($request->user()), 403, 'You do not have access to the competency matrix.');

        return $next($request);
    }
}
