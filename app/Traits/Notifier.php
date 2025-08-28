<?php

namespace App\Traits;

use App\Models\Role;
use Illuminate\Support\Facades\Notification;

trait Notifier
{

    private array|null $data = null;

    private string $to;

    /**
     * @param string $roleName
     * @param $notification
     * @param $data
     * @param $model
     * @return void
     */
    public function notifyRole(string $roleName, $notification, $data, $model): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $role->notify(new $notification($data, $model['type'], $model['model_id'], $model['model_type']));
    }


    public function notifyUser(string $to, array $data = []): static
    {
        $this->to = $to;
        $this->data = $data;

        return $this;
    }

    public function send($notification): void
    {
        Notification::route('mail', $this->to)
            ->notify(new $notification($this->data));
    }
}
