<?php

namespace App\Http\Controllers\Payroll;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\Client;
use App\Models\Payroll\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Clients and their work orders / POs (one list, as people look them up together). */
class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'clients'     => Client::orderByDesc('active')->orderBy('name')->get()->map(fn ($c) => self::client($c))->values(),
            'work_orders' => WorkOrder::with('client')->orderByDesc('active')->orderByDesc('start_date')->orderBy('number')->get()
                ->map(fn ($w) => self::workOrder($w))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success(self::client(Client::create($this->validatedClient($request))), 'Client added.', 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $client->update($this->validatedClient($request));

        return ApiResponse::success(self::client($client), 'Client saved.');
    }

    public function destroy(Client $client): JsonResponse
    {
        if ($client->workOrders()->exists()) {
            throw new UserFacingException("{$client->name} has work orders. Remove them or mark the client inactive instead.");
        }
        $client->delete();

        return ApiResponse::success(null, 'Client removed.');
    }

    public function storeWorkOrder(Request $request): JsonResponse
    {
        $order = WorkOrder::create($this->validatedWorkOrder($request));

        return ApiResponse::success(self::workOrder($order->load('client')), 'Work order added.', 201);
    }

    public function updateWorkOrder(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $workOrder->update($this->validatedWorkOrder($request, $workOrder));

        return ApiResponse::success(self::workOrder($workOrder->load('client')), 'Work order saved.');
    }

    public function destroyWorkOrder(WorkOrder $workOrder): JsonResponse
    {
        $workOrder->delete();

        return ApiResponse::success(null, 'Work order removed.');
    }

    public static function client(Client $c): array
    {
        return ['uuid' => $c->uuid, 'name' => $c->name, 'active' => $c->active];
    }

    public static function workOrder(WorkOrder $w): array
    {
        return [
            'uuid'        => $w->uuid,
            'number'      => $w->number,
            'description' => $w->description,
            'client'      => $w->client ? ['uuid' => $w->client->uuid, 'name' => $w->client->name] : null,
            'start_date'  => $w->start_date?->toDateString(),
            'end_date'    => $w->end_date?->toDateString(),
            'active'      => $w->active,
        ];
    }

    private function validatedClient(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:255'], 'active' => ['boolean']]);
    }

    private function validatedWorkOrder(Request $request, ?WorkOrder $order = null): array
    {
        $data = $request->validate([
            'number'      => ['required', 'string', 'max:50', Rule::unique('work_orders', 'number')->ignore($order?->id)->whereNull('deleted_at')],
            'client_uuid' => ['nullable', Rule::exists('clients', 'uuid')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:255'],
            'start_date'  => ['nullable', 'date'],
            'end_date'    => ['nullable', 'date', 'after_or_equal:start_date'],
            'active'      => ['boolean'],
        ], ['number.unique' => 'Another work order has this number.']);

        $data['client_id'] = isset($data['client_uuid']) ? Client::where('uuid', $data['client_uuid'])->value('id') : null;
        unset($data['client_uuid']);

        return $data;
    }
}
