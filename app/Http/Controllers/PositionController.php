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

        return new PositionResource($position->loadCount('jobDetails'));
    }

    public function update(UpdatePositionRequest $request, Position $position): PositionResource
    {
        $position->update($request->validated());

        return new PositionResource($position->fresh()->loadCount('jobDetails'));
    }

    public function destroy(Position $position): JsonResponse
    {
        $uuid = $position->uuid;
        $position->delete();

        return response()->json(['id' => $uuid]);
    }
}
