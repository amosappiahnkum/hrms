<?php

namespace App\Http\Resources\Recruitment;

class CandidateProfileResource extends CandidateResource
{
    public function toArray($request): array
    {
        $data = parent::toArray($request);
        $data['profile_completion'] = $this->profileCompletion();

        return $data;
    }
}
