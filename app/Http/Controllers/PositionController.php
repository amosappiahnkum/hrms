<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePositionRequest;
use App\Http\Requests\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PositionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $positions = Position::withCount('jobDetails')
            ->when($request->filled('search'), fn($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->paginate($request->per_page ?? 10);

        return PositionResource::collection($positions);
    }

    public function store(StorePositionRequest $request): PositionResource
    {
        $position = Position::create($request->validated());

        activity('positions')->performedOn($position)->log("Created position: {$position->name}");

        return new PositionResource($position->loadCount('jobDetails'));
    }

    public function update(UpdatePositionRequest $request, Position $position): PositionResource
    {
        $position->update($request->validated());

        activity('positions')->performedOn($position)->log("Updated position: {$position->name}");

        return new PositionResource($position->fresh()->loadCount('jobDetails'));
    }

    public function destroy(Position $position): JsonResponse
    {
        $totalEmployees = $position->jobDetails()->count();

        if ($totalEmployees > 0) {
            return response()->json([
                'success' => false,
                'message' => "You can't delete this position because it has employees."
            ], 400);
        }

        $uuid = $position->uuid;
        $name = $position->name;
        $position->delete();

        activity('positions')->log("Deleted position: {$name}");

        return response()->json(['id' => $uuid]);
    }
}
