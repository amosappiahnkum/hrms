<?php

namespace App\Traits;

use App\Notifications\InfoUpdateNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Traits\EnumeratesValues;
use JsonException;

trait InformationUpdate
{

    use SoftDeletes;

    protected $infoUpdate;

    private array $newUpdate;

    /**
     * @param Model $oldInfo
     * @param array $newInfo
     *
     *
     * @throws JsonException
     */
    public function infoDifference(Model $oldInfo, array $newInfo = []): void
    {
        unset($newInfo['id'], $newInfo['file'], $newInfo['employee_id']);
        $newData = json_decode(json_encode($newInfo, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $oldData = json_decode(json_encode($oldInfo, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $difference = array_diff_assoc($newData, $oldData);

        $difference = array_diff($difference, ["null"]);
        unset($difference['_method']);

        $this->newUpdate = $difference;
    }

    protected function requestUpdate(Model $model, array $changes)
    {
        return $model->informationUpdate()->updateOrCreate([
            'information_type' => $model->getMorphClass(),
            'information_id' => $model->id,
            'status' => 'pending',
        ], [
                'old_info' => $model->only(array_keys($changes)),
                'new_info' => $changes,
                'requested_by' => Auth::id(),
            ]
        );
    }


    public function notify($data, $employeeId, array $modelInfo): void
    {
        if ($this->infoUpdate) {
            $info = [
                "title" => $data['title'],
                "message" => $data['message'],
                'data' => 'info_update',
                'data_id' => $this->infoUpdate->id,
                "employee" => $employeeId,
            ];

            $this->notifyRole("hr", InfoUpdateNotification::class, $info, $modelInfo);
        }
    }
}
