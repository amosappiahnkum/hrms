<?php

namespace App\Providers;

use App\Models\JobDetail;
use App\Models\LeaveRequest;
use App\Models\PerformanceAppraisal\Appraisal;
use App\Models\Photo;
use App\Models\SelfService\Achievement;
use App\Models\SelfService\Affiliation;
use App\Models\SelfService\Award;
use App\Models\SelfService\CommunityService;
use App\Models\SelfService\ContactDetail;
use App\Models\SelfService\Dependant;
use App\Models\SelfService\Education;
use App\Models\SelfService\EmergencyContact;
use App\Models\SelfService\Employee;
use App\Models\SelfService\Experience;
use App\Models\SelfService\GrantAndFund;
use App\Models\SelfService\NextOfKin;
use App\Models\SelfService\Project;
use App\Models\SelfService\Publication;
use App\Models\Training\PreviousPosition;
use App\Models\Training\PreviousRank;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
//        if (env('APP_ENV') != 'local') {
//            URL::forceScheme('https');
//        }

        JsonResource::withoutWrapping();
        RateLimiter::for("login", static function () {
            Limit::perMinute(5);
        });

        Relation::morphMap([
            'Employee' => Employee::class,
            'Experience' => Experience::class,
            'ContactDetail' => ContactDetail::class,
            'NextOfKin' => NextOfKin::class,
            'EmergencyContact' => EmergencyContact::class,
            'Dependant' => Dependant::class,
            'Education' => Education::class,
            'Photo' => Photo::class,
            'JobDetail' => JobDetail::class,
            'Award' => Award::class,
            'Achievement' => Achievement::class,
            'Affiliation' => Affiliation::class,
            'Publication' => Publication::class,
            'Project' => Project::class,
            'GrantAndFund' => GrantAndFund::class,
            'CommunityService' => CommunityService::class,
            'PreviousRank' => PreviousRank::class,
            'PreviousPosition' => PreviousPosition::class,
            'LeaveRequest' => LeaveRequest::class,
            'Appraisal' => Appraisal::class,
        ]);
    }
}
