<?php

namespace App\Http\Middleware;

use App\Services\TrainingPlan\TrainingPlanAccess as Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Training plans are for training plan permission holders and heads of department (their own staff).
 * Extra permissions that also let someone through can be listed: `training-plan.access:manage-certifications`.
 */
class TrainingPlanAccess
{
    public function __construct(private readonly Access $access)
    {
    }

    public function handle(Request $request, Closure $next, string ...$alsoAllowed): Response
    {
        $user = $request->user();
        abort_unless($user && ($this->access->hasAccess($user) || ($alsoAllowed && $user->canAny($alsoAllowed))), 403, 'You do not have access to training plans.');

        return $next($request);
    }
}
