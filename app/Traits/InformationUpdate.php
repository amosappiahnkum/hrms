<?php

namespace App\Traits;

use App\Notifications\InfoUpdateNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        unset($newInfo['id'], $newInfo['file']);
        $newData = json_decode(json_encode($newInfo, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $oldData = json_decode(json_encode($oldInfo, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $difference = array_diff_assoc($newData, $oldData);

        $difference = array_diff($difference, ["null"]);
        unset($difference['_method']);

        $this->newUpdate = $difference;
    }

    public function requestUpdate(Model $model)
    {
        if (count($this->newUpdate) > 0) {

            $reflection = new \ReflectionClass($model);

            return $this->infoUpdate = $model->informationUpdate()->updateOrCreate([
                'information_type' => $reflection->getShortName(),
                'information_id' => $model->id,
                'status' => 'pending'
            ], [
                'old_info' => $model->only(array_keys($this->newUpdate)),
                'new_info' => $this->newUpdate,
                'requested_by' => Auth::id()
            ]);
        }

        return null;
    }

    public function notify($data, $employeeId, array $modelInfo): void
    {
        Log::info('here');
        if ($this->infoUpdate) {
            $info = [
                "title" => $data['title'],
                "message" => $data['message'],
                'data' => 'info_update',
                'data_id' => $this->infoUpdate->id,
                "employee" => $employeeId,
            ];

            Log::info('inside');
            $this->notifyRole("hr", InfoUpdateNotification::class, $info, $modelInfo);
        }
    }
}
