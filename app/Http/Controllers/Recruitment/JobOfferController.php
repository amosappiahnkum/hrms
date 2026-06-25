<?php

namespace App\Http\Controllers\Recruitment;

use App\Enums\ApplicationStatus;
use App\Enums\OfferStatus;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Recruitment\StoreJobOfferRequest;
use App\Http\Requests\Recruitment\UpdateJobOfferRequest;
use App\Http\Resources\Recruitment\JobOfferResource;
use App\Models\Recruitment\Application;
use App\Models\Recruitment\JobOffer;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JobOfferController extends Controller
{
    public function index(Application $application): AnonymousResourceCollection
    {
        $offers = $application->offer()->get();
        return JobOfferResource::collection($offers);
    }

    public function store(StoreJobOfferRequest $request, Application $application): JobOfferResource|JsonResponse
    {
        try {
            $offer = $application->offer()->create($request->validated());

            $application->update(['status' => ApplicationStatus::OFFERED]);

            activity('recruitment')->performedOn($offer)->log("Job offer created for application #{$application->id}");

            return new JobOfferResource($offer);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function show(JobOffer $offer): JsonResponse
    {
        $offer->load('application.candidate');
        return ApiResponse::success(new JobOfferResource($offer));
    }

    public function update(UpdateJobOfferRequest $request, JobOffer $offer): JobOfferResource|JsonResponse
    {
        try {
            $offer->update($request->validated());

            if ($offer->status === OfferStatus::ACCEPTED) {
                $offer->application->update(['status' => ApplicationStatus::OFFERED]);
            }

            activity('recruitment')->performedOn($offer)
                ->withProperties(['status' => $offer->status])
                ->log("Updated job offer #{$offer->id}");

            return new JobOfferResource($offer->refresh());
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy(JobOffer $offer): JsonResponse
    {
        try {
            $offer->delete();
            activity('recruitment')->log("Deleted job offer #{$offer->id}");
            return response()->json(['message' => 'Offer deleted']);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
