<?php

namespace App\Services;

use App\Events\ApprovalApproved;
use App\Events\ApprovalRejected;
use App\Events\ApprovalRequested;
use App\Models\InformationUpdate;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class UpdateApprovalService
{
    /**
     * @throws Exception
     */
    public function create(Model $model, array $data, int $userId): ?InformationUpdate
    {

        if (empty($data)) return null;

        $createData = $this->filterApprovableFields($model, $data);

        $approval = InformationUpdate::create([
            'type' => 'create',

            'information_type' => $model->getMorphClass(),

            'information_id' => null,

            'old_info' => [],

            'new_info' => $createData,

            'status' => 'pending',

            'requested_by' => $userId,
        ]);

        event(new ApprovalRequested($approval));

        return $approval;
    }

    /**
     * @throws Exception
     */
    public function update(Model $model, array $data, int $userId): ?InformationUpdate
    {

        if ($model->informationUpdates()->where('status', 'pending')->exists()) {
            throw new Exception('Pending request already exists');
        }

        $changes = $this->getDifferences($model, $data);

        if (empty($changes)) {
            return null;
        }

        $approval = InformationUpdate::create([
            'type' => 'update',

            'information_type' => $model->getMorphClass(),

            'information_id' => $model->id,

            'old_info' => $model->only(array_keys($changes)),

            'new_info' => $changes,

            'status' => 'pending',

            'requested_by' => $userId,
        ]);

        event(new ApprovalRequested($approval));

        return $approval;
    }

    public function delete(Model $model, int $userId): ?InformationUpdate
    {

        if ($model->informationUpdates()->where('status', 'pending')->exists()) {
            throw new Exception('Pending request already exists');
        }

        $approval = InformationUpdate::create([
            'type' => 'delete',

            'information_type' => $model->getMorphClass(),

            'information_id' => $model->id,

            'old_info' => $model->toArray(),

            'new_info' => [],

            'status' => 'pending',

            'requested_by' => $userId,
        ]);

        event(new ApprovalRequested($approval));

        return $approval;
    }

    /**
     * @throws Throwable
     */
    public function approve(InformationUpdate $update, int $reviewerId): void
    {
        DB::transaction(function () use ($update, $reviewerId) {

            if ($update->status->value !== 'pending') {
                throw new Exception('Already processed');
            }

            $modelClass = Relation::getMorphedModel($update->information_type)
                ?? $update->information_type;

            if ($update->type === 'create') {
                $model = $modelClass::create([...$update->new_info, 'user_id' => null]);

                // link back
                $update->update([
                    'information_id' => $model->id,
                ]);

            } elseif ($update->type === 'update') {
                $model = $update->information;
                $model->update($update->new_info);

            } elseif ($update->type === 'delete') {
                $model = $update->information;
                $model->delete();
            }

            $update->update([
                'status' => 'approved',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);


            event(new ApprovalApproved($update));

            $this->afterApproval($model, $update);
        });
    }

    /**
     * @throws Exception
     */
    public function reject(InformationUpdate $update, int $reviewerId, string $reason): void
    {
        if ($update->status->value !== 'pending') {
            throw new Exception('Already processed');
        }

        $update->update([
            'status' => 'rejected',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        event(new ApprovalRejected($update));
    }

    protected function afterApproval($model, $update): void
    {
        if (method_exists($model, 'afterApproval')) {
            $model->afterApproval($update->new_info);
        }
    }

    protected function filterApprovableFields(Model $model, array $data): array
    {
        return $this->baseFilter($model, $data)->toArray();
    }

    protected function getDifferences(Model $model, array $data): array
    {
        return $this->baseFilter($model, $data)
            ->filter(fn($value, $key) => $model->{$key} != $value)
            ->toArray();
    }

    protected function baseFilter(Model $model, array $data): Collection
    {
        $fieldConfig = method_exists($model, 'approvableFields')
            ? $model->approvableFields()
            : array_fill_keys($model->getFillable(), []);

        // Extract the keys from the metadata array
        $allowedKeys = array_keys($fieldConfig);

        return collect($data)
            ->only($allowedKeys)
            ->reject(fn($value) => is_null($value) || is_array($value) || is_object($value));
    }
}
