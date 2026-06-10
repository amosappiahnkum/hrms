<?php

namespace App\Http\Resources;

use App\Helpers\Helper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserManagementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid'               => $this->uuid,
            'name'               => $this->name,
            'username'           => $this->username,
            'email'              => $this->email,
            'phone_number'       => $this->phone_number,
            'last_login_at'      => $this->last_login_at?->toDateTimeString(),
            'suspended'          => (bool) $this->deleted_at,
            'suspended_at'       => $this->deleted_at?->toDateTimeString(),
            'roles'              => $this->roles->pluck('name'),
            'direct_permissions' => $this->getDirectPermissions()->pluck('name'),
            'employee'           => $this->employee ? [
                'uuid'     => $this->employee->uuid,
                'name'     => $this->employee->name,
                'staff_id' => $this->employee->staff_id,
                'title'    => $this->employee->title,
                'photo'    => Helper::getTempPhoto($this->employee->photo),
            ] : null,
        ];
    }
}
