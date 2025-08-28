<?php

namespace App\Notifications;

use Carbon\Carbon;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class InfoUpdateNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    private array $data;

    public string $type;

    public int $model_id;

    public string $model_type;

    /**
     * Create a new notification instance.
     */
    public function __construct(array $data, string $type, int $model_id, string $model_type)
    {
        $this->data = $data;
        $this->type = $type;
        $this->model_id = $model_id;
        $this->model_type = $model_type;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [CustomDbChannel::class, 'broadcast'];
    }

    public function viaConnections()
    {
        return [
            'database' => 'sync',
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    /**
     * Get the notification's database type.
     *
     * @param object $notifiable
     * @return string
     */
    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }

    /**
     * Get the data to broadcast.
     *
     * //     * @return array<string, mixed>
     */
    public function toBroadcast(object $notifiable)
    {
        return [
            'data' => $this->data,
            'type' => $this->type,
        ];
    }

    /**
     * Get the broadcast channel.
     *
     * @return Channel
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel('notify-user.hr');
    }


    public function broadcastWith(): array
    {
        $not = DB::table('notifications')->where('id', $this->id)->first();

        $data = (array)$not;

        return [
            'id' => $data['id'],
            "type" => $data['type'],
            "data" => json_decode($data['data']),
            "read_at" => $data['read_at'],
            "when" => Carbon::parse($data['created_at'])->diffForHumans(),
        ];
    }
}
