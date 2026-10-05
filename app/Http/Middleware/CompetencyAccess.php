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

    /**
     * With "library", people preparing the training plan (linking trainings to competencies) and
     * certificate managers (typing certificates) get in too: the library holds no employee data.
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $user = $request->user();
        $preparesTraining = $scope === 'library' && $user?->can('prepare-training-plan') && feature('training_plan.enabled');
        // Certificate managers pick a certificate's type from the library.
        $managesCertificates = $scope === 'library' && $user?->can('manage-certifications');

        abort_unless($user && ($preparesTraining || $managesCertificates || $this->service->hasAccess($user)), 403, 'You do not have access to the competency matrix.');

        return $next($request);
    }
}
