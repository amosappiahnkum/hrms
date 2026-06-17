<?php

namespace App\Http\Controllers;


use App\Http\Resources\InfoUpdateResource;
use App\Http\Resources\NotificationResource;
use App\Models\InformationUpdate;
use App\Models\SelfService\Employee;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NotificationController extends Controller
{
    protected array $notificationData = [
        'info_update' => InformationUpdate::class
    ];

    public function getNotifications(string $type): JsonResponse
    {
        $user = Auth::user();
        $notifications = collect();

        foreach ($user?->roles as $role) {

            $nots = $type == "read" ? $role->notifications->where('read_at', '!=', null) : $role->unreadNotifications;

            if ($nots->count() > 0) {
                $notifications->add($nots);
            }
        }

        return response()->json([
            'data' => NotificationResource::collection($notifications->flatten())
        ]);
    }

    /**
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function getApprovals(Request $request): AnonymousResourceCollection
    {
        $query = InformationUpdate::query()->orderByDesc('created_at');

        $status = $request->status ?? 'pending';
        if ($status && $status !== 'all') {
            $query->where('status', ucfirst($status));
        }

        if ($type = $request->type) {
            if ($type !== 'all') {
                $query->where('type', $type);
            }
        }

        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('information_type', 'like', "%{$search}%")
                    ->orWhereHas('requestedBy.employee', function ($q) use ($search) {
                        $q->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('staff_id', 'like', "%{$search}%")
                            ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$search}%"]);
                    });
            });
        }

        return InfoUpdateResource::collection($query->paginate($request->per_page ?? 10));
    }


    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getNotificationDetail(Request $request): JsonResponse
    {
//        $query = $request->query();
        $data = null;
        $employee = null;

        if ($this->isDataAvailable('data', $request->all())) {
            $model = $this->notificationData[$request->data];
            $data = $model::find($request->id);
        }

        if ($this->isDataAvailable('employee', $request->all())) {
            $find = Employee::query()->find($request->employee);

            $employee = new EmployeeDataForNotificationResource($find);
        }

        $this->readNotification($request->notificationId);

        return response()->json([
            'employee' => $employee,
            'data' => $data
        ]);
    }

    public function isDataAvailable($key, array $data): bool
    {
        return array_key_exists($key, $data) && $data[$key] !== null;
    }


    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function markAsRead(Request $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $this->readNotification($request->id);

            DB::commit();

            return response()->json(["message" => "success"]);
        } catch (Exception $exception) {
            DB::rollBack();

            throw new RuntimeException($exception->getMessage(), 400);
        }
    }
}
