<?php

namespace App\Models;

use App\scopes\ActivityLogScope;
use App\Traits\HasUuid;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory, HasUuid;

    protected $guarded = [
        'created_at',
        'updated_at'
    ];

    public static function add(
        string $activityComposition,
        string $activity,
        array $actionables,
        string $scope = null
    ): ActivityLog {
        return new ActivityLog([
            'activity_composition' => $activityComposition,
            'activity' => $activity,
            'scope' => $scope,
            'actionables' => json_encode($actionables)
        ]);
    }

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new ActivityLogScope());
    }

    public function to($loggable): ActivityLog
    {
        $this->loggable()->associate($loggable);

        return $this;
    }

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function as($initiator): void
    {
        $this->initiator()->associate($initiator);
        $this->save();
    }

    public function initiator(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @throws Exception
     */
    public function withActionables(array $actionables): ActivityLog
    {
        foreach ($actionables as $actionable) {
            $transformed = (array)$actionable;
            if (!array_key_exists('key', $transformed) || !array_key_exists('name', $transformed)) {
                throw new Exception('Actionable should have a :key and :name');
            }
        }
        $this->actionables = json_encode($actionables);

        return $this;
    }
}
