<?php

namespace App\Providers;

use App\Models\Recruitment\Application;
use App\Models\Recruitment\Candidate;
use App\Models\Recruitment\Interview;
use App\Models\Recruitment\JobOffer;
use App\Models\Recruitment\JobOpening;
use App\Models\SelfService\GrantAndFund;
use App\Models\SelfService\Publication;
use App\Policies\ApplicationPolicy;
use App\Policies\CandidatePolicy;
use App\Policies\GrantAndFundPolicy;
use App\Policies\InterviewPolicy;
use App\Policies\JobOfferPolicy;
use App\Policies\JobOpeningPolicy;
use App\Policies\PublicationPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        Publication::class => PublicationPolicy::class,
        GrantAndFund::class => GrantAndFundPolicy::class,
        JobOpening::class => JobOpeningPolicy::class,
        Candidate::class => CandidatePolicy::class,
        Application::class => ApplicationPolicy::class,
        Interview::class => InterviewPolicy::class,
        JobOffer::class => JobOfferPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        //
    }
}
