<?php

namespace App\Http\Resources\Recruitment;

use App\Models\Config\Setting;
use Illuminate\Http\Resources\Json\JsonResource;

class CandidateAuthResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'email'            => $this->email,
            'onboarding'       => true,
            'password_changed' => true,
            'roles'            => ['candidate'],
            'permissions'      => [],
            'address'          => '',
            'user_type'        => 'candidate',
            'user_info'        => ['id' => $this->id],
            'info'             => [
                'id'              => $this->uuid,
                'first_name'      => $this->first_name,
                'last_name'       => $this->last_name,
                'employee_id'     => null,
                'staff_id'        => null,
                'gender'          => $this->gender ?? '',
                'phone_number'    => $this->phone ?? '',
                'display_picture' => 'default.png',
                'title'           => '',
                'marital_status'  => '',
                'job_type'        => '',
                'job_category_id' => null,
                'department_id'   => null,
                'department'      => null,
            ],
            'settings' => $this->loadSettings(),
        ];
    }

    private function loadSettings(): array
    {
        $all = Setting::query()
            ->where('group', 'app')
            ->orWhere('key', 'like', 'features.%')
            ->get();

        return [
            'app'      => $all->where('group', 'app')
                ->mapWithKeys(fn($s) => [last(explode('.', $s->key)) => $s->value])
                ->toArray(),
            'features' => $all->filter(fn($s) => str_starts_with($s->key, 'features.'))
                ->mapWithKeys(fn($s) => [str_replace('features.', '', $s->key) => $s->value])
                ->toArray(),
            'forms'    => [],
        ];
    }
}
