<?php

namespace App\Http\Resources\Recruitment;

use Illuminate\Http\Resources\Json\JsonResource;

class CandidateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'uuid'               => $this->uuid,
            'name'               => $this->name,
            'first_name'         => $this->first_name,
            'last_name'          => $this->last_name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'source'             => $this->source,
            'cv_path'            => $this->cv_path,

            // Extended profile fields
            'date_of_birth'      => $this->date_of_birth?->format('Y-m-d'),
            'gender'             => $this->gender,
            'nationality'        => $this->nationality,
            'country'            => $this->country,
            'region'             => $this->region,
            'address'            => $this->address,
            'summary'            => $this->summary,
            'salary_expectation' => $this->salary_expectation,
            'salary_currency'    => $this->salary_currency,
            'linkedin_url'       => $this->linkedin_url,
            'portfolio_url'      => $this->portfolio_url,

            // Sub-relations (loaded on demand)
            'experiences'     => $this->whenLoaded('experiences', fn() =>
                $this->experiences->map(fn($e) => [
                    'uuid'         => $e->uuid,
                    'company_name' => $e->company_name,
                    'job_title'    => $e->job_title,
                    'start_date'   => $e->start_date?->format('Y-m-d'),
                    'end_date'     => $e->end_date?->format('Y-m-d'),
                    'is_current'   => $e->is_current,
                    'description'  => $e->description,
                    'created_at'   => $e->created_at,
                ])
            ),

            'qualifications'  => $this->whenLoaded('qualifications', fn() =>
                $this->qualifications->map(fn($q) => [
                    'uuid'           => $q->uuid,
                    'institution'    => $q->institution,
                    'award'          => $q->award,
                    'field_of_study' => $q->field_of_study,
                    'start_date'     => $q->start_date?->format('Y-m-d'),
                    'end_date'       => $q->end_date?->format('Y-m-d'),
                    'grade'          => $q->grade,
                    'created_at'     => $q->created_at,
                ])
            ),

            'skills'          => $this->whenLoaded('skills', fn() =>
                $this->skills->map(fn($s) => [
                    'uuid'       => $s->uuid,
                    'name'       => $s->name,
                    'level'      => $s->level,
                    'created_at' => $s->created_at,
                ])
            ),

            'languages'       => $this->whenLoaded('languages', fn() =>
                $this->languages->map(fn($l) => [
                    'uuid'        => $l->uuid,
                    'name'        => $l->name,
                    'proficiency' => $l->proficiency,
                    'created_at'  => $l->created_at,
                ])
            ),

            'documents'       => $this->whenLoaded('documents', fn() =>
                $this->documents->map(fn($d) => [
                    'uuid'         => $d->uuid,
                    'type'         => $d->type,
                    'display_name' => $d->display_name,
                    'path'         => $d->path,
                    'mime_type'    => $d->mime_type,
                    'size'         => $d->size,
                    'created_at'   => $d->created_at,
                ])
            ),

            'applications'    => $this->whenLoaded('applications', fn() =>
                $this->applications->map(fn($a) => [
                    'uuid'        => $a->uuid,
                    'status'      => $a->status,
                    'applied_at'  => $a->applied_at?->format('Y-m-d H:i:s'),
                    'job_opening' => $a->jobOpening ? [
                        'uuid'       => $a->jobOpening->uuid,
                        'title'      => $a->jobOpening->title,
                        'department' => $a->jobOpening->department?->name ?? null,
                        'location'   => $a->jobOpening->location,
                    ] : null,
                ])
            ),

            'created_at'         => $this->created_at,
        ];
    }
}
