<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AuthResponseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'first_name'=> $this->employee?->first_name,
            'last_name'=> $this->employee?->last_name,
            'other_names'=> $this->employee?->other_names,
            'phone_number'=> $this->employee?->phone_number,
            'email'=> $this->email,
            'password_changed'=> $this->password_changed == 1,
            'display_picture'=> null,
            'roles' => $this->getRoleNames(),
            'permissions' => $this->getPermissionsViaRoles()->pluck('name')->merge
            ($this->getDirectPermissions()->pluck('name'))
        ];
    }
}
