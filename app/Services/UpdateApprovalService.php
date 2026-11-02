<?php

namespace App\Services;

use App\Events\ApprovalApproved;
use App\Events\ApprovalRejected;
use App\Events\ApprovalRequested;
use App\Exceptions\UserFacingException;
use App\Models\InformationUpdate;
use Carbon\Carbon;
use DateTimeInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        $pending = $model->informationUpdates()->where('status', 'pending')->latest()->first();

        if ($pending) {
            // The requester may correct their own pending update (e.g. fix a typo) before it is reviewed.
            if ($pending->type === 'update' && (int) $pending->requested_by === $userId) {
                return $this->amend($pending, $model, $data);
            }

            throw new UserFacingException('A change request for this record is already pending approval.', 409);
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

    /**
     * Merge new changes into an existing pending update. Fields submitted with
     * their current (live) value are dropped from the request; if nothing is
     * left to change, the request is canceled.
     */
    protected function amend(InformationUpdate $pending, Model $model, array $data): ?InformationUpdate
    {
        $submitted = $this->baseFilter($model, $data);

        $changes = collect($pending->new_info ?? [])
            ->merge($this->getDifferences($model, $data))
            ->reject(fn($value, $key) => $submitted->has($key) && $this->isUnchanged($model, $key, $submitted->get($key)))
            ->toArray();

        if (empty($changes)) {
            $pending->update(['status' => 'canceled']);

            return null;
        }

        $pending->update([
            'old_info' => $model->only(array_keys($changes)),
            'new_info' => $changes,
        ]);

        return $pending;
    }

    /**
     * Replace the details of a pending "create" request, so the requester can
     * correct a new record before it is reviewed. $data is filtered exactly as
     * it is in create().
     */
    public function amendCreate(InformationUpdate $update, array $data, int $userId): InformationUpdate
    {
        if ((int) $update->requested_by !== $userId) {
            throw new UserFacingException('You can only edit your own requests.', 403);
        }

        if ($update->type !== 'create' || $update->status->value !== 'pending') {
            throw new UserFacingException('Only new records awaiting approval can be edited here.', 409);
        }

        $modelClass = Relation::getMorphedModel($update->information_type) ?? $update->information_type;

        $update->update([
            'new_info' => $this->filterApprovableFields(new $modelClass, $data),
        ]);

        return $update;
    }

    public function delete(Model $model, int $userId): ?InformationUpdate
    {

        if ($model->informationUpdates()->where('status', 'pending')->exists()) {
            throw new UserFacingException('A change request for this record is already pending approval.', 409);
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
                throw new UserFacingException('This request has already been processed.', 409);
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
            throw new UserFacingException('This request has already been processed.', 409);
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
            ->reject(fn($value, $key) => $this->isUnchanged($model, $key, $value))
            ->toArray();
    }

    /**
     * Date-cast attributes are Carbon instances, which never loosely equal the
     * submitted string ("2018-09-01"), so compare those as dates.
     */
    protected function isUnchanged(Model $model, string $key, mixed $value): bool
    {
        $current = $model->{$key};

        if ($current instanceof DateTimeInterface) {
            try {
                return Carbon::parse($value)->equalTo($current);
            } catch (Throwable) {
                return false;
            }
        }

        return $current == $value;
    }

    /**
     * Forms send related records by uuid (e.g. department_uuid) and the request swaps
     * them for ids, so map each belongsTo `*_id` in $values back to its `*_uuid`.
     * Gives values that can pre-fill the form that submitted them.
     */
    public function formValues(Model $model, array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value === null || !str_ends_with($key, '_id')) {
                continue;
            }

            $name = Str::beforeLast($key, '_id');
            $relation = Str::camel($name);

            if (!method_exists($model, $relation) || !($model->{$relation}() instanceof BelongsTo)) {
                continue;
            }

            $uuid = $model->{$relation}()->getRelated()->newQuery()->whereKey($value)->value('uuid');

            if ($uuid) {
                $values["{$name}_uuid"] = $uuid;
            }
        }

        return $values;
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
