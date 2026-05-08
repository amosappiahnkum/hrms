<?php

namespace App\Http\Controllers\SelfService;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePreviousRankRequest;
use App\Http\Requests\UpdatePreviousRankRequest;
use App\Http\Resources\PreviousRankResource;
use App\Models\PreviousRank;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PreviousRankController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $previousRanks = PreviousRank::where('employee_id', $request->employeeId)->paginate(10);

        return PreviousRankResource::collection($previousRanks);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StorePreviousRankRequest $request
     * @return PreviousRankResource|JsonResponse
     */
    public function store(StorePreviousRankRequest $request): PreviousRankResource|JsonResponse
    {
        DB::beginTransaction();
        try {
            $request['user_id'] = Auth::user()->id;
            $previousRank = PreviousRank::create($request->all());
            DB::commit();

            return new PreviousRankResource($previousRank);
        }catch (Exception $exception){
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param UpdatePreviousRankRequest $request
     * @param $id
     * @return PreviousRankResource|JsonResponse
     */
    public function update(UpdatePreviousRankRequest $request, $id)
    {
        DB::beginTransaction();
        try {
            $previousRank = PreviousRank::findOrFail($id);
            $previousRank->update($request->all());

            DB::commit();
            return new PreviousRankResource($previousRank);
        }catch (Exception $exception){
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse|null
     */
    public function destroy($id): ?JsonResponse
    {
        DB::beginTransaction();
        try {
            $previousRank = PreviousRank::findOrFail($id);
            $previousRank->delete();
            DB::commit();
            return response()->json([
                'message' =>'Emergency Contact Deleted'
            ]);
        }catch (Exception $exception){
            return response()->json([
                'message' => $exception->getMessage()
            ], 400);
        }
    }
}
