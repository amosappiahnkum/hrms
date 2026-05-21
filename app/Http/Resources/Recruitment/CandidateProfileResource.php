<?php

namespace App\Http\Resources\Recruitment;

class CandidateProfileResource extends CandidateResource
{
    public function toArray($request): array
    {
        // Ensure all sub-relations are always present in the portal "me" response
        $this->resource->loadMissing([
            'experiences',
            'qualifications',
            'skills',
            'languages',
            'documents',
        ]);

        $data = parent::toArray($request);
        $data['profile_completion'] = $this->profileCompletion();

        return $data;
    }
}
