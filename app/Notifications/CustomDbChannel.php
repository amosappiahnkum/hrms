<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class CustomDbChannel
{
    public function send($notifiable, Notification $notification)
    {
        $data = $notification->toDatabase($notifiable);

        return $notifiable->routeNotificationFor('database')->create([
            'id' => $notification->id,
            'model_type' => $notification->model_type,
            'model_id' => $notification->model_id,
            'type' => $notification->type,
            'data' => $data,
            'read_at' => null,
        ]);
    }
}
