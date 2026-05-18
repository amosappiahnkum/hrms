<?php

namespace App\Providers;

use App\Models\SelfService\GrantAndFund;
use App\Models\SelfService\Publication;
use App\Policies\GrantAndFundPolicy;
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
