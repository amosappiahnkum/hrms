<?php

namespace App\Http\Controllers;


use App\Http\Resources\InfoUpdateResource;
use App\Http\Resources\NotificationResource;
use App\Models\Employee;
use App\Models\InformationUpdate;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $query = InformationUpdate::query();

        $query->where('status', "Pending");

        $query->orderByDesc('created_at');

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
