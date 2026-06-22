<?php

namespace App\Http\Resources;

use App\Helpers\Helper;
use App\Models\Config\Setting;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResponseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    private static function loadSettings(): array
    {
        $all = Setting::query()
            ->where('group', 'app')
            ->orWhere('key', 'like', 'features.%')
            ->orWhere('key', 'like', 'forms.%')
            ->get();

        return [
            'app' => $all->where('group', 'app')
                ->mapWithKeys(fn($s) => [last(explode('.', $s->key)) => $s->value])
                ->toArray(),
            'features' => $all->filter(fn($s) => str_starts_with($s->key, 'features.'))
                ->mapWithKeys(fn($s) => [str_replace('features.', '', $s->key) => $s->value])
                ->toArray(),
            'forms' => $all->filter(fn($s) => str_starts_with($s->key, 'forms.'))
                ->mapWithKeys(fn($s) => [str_replace('forms.', '', $s->key) => $s->value])
                ->toArray(),
        ];
    }

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'info' => [
                "id" => $this->employee?->uuid,
                "title" => $this->employee?->title,
                "first_name" => $this->employee?->first_name,
                "last_name" => $this->employee?->last_name,
                "other_names" => $this->employee?->other_names,
                "staff_id" => $this->employee?->staff_id,
                "gender" => $this->employee?->gender,
                "marital_status" => $this->employee?->marital_status,
                "phone_number" => $this->employee?->phone_number,
                "display_picture" => Helper::getTempPhoto($this->employee?->photo),
                "job_type" => $this->employee?->job_type,
                "job_category_id" => $this->employee?->jobDetail?->job_category_id,
                "employee_id" => $this?->employee?->uuid ?? null,
                "department_id" => $this?->employee?->department_id ?? null,
                "department" => new DepartmentResource($this?->employee?->department)
            ],
            'onboarding' => $this->employee?->onboarding == 1,
            'email' => $this->email,
            'password_changed' => $this->password_changed == 1,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getPermissionsViaRoles()->pluck('name')->merge($this->getDirectPermissions()->pluck('name')),
            'user_type' => $this->hasRole('super-admin') ? 'super_admin' : 'employee',
            'settings' => self::loadSettings(),
        ];
    }
}
