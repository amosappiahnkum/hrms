<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreDynamicFieldValueRequest;
use App\Services\DynamicValidationService;
use App\Services\DynamicValueService;
use Illuminate\Database\Eloquent\Relations\Relation;

class DynamicFieldValueController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDynamicFieldValueRequest $request, DynamicValidationService $validator, DynamicValueService $service)
    {
        $validated = $request->validated();

        $modelClass = $validated['model'];

        $model = $modelClass::where('uuid', $validated['model_id'])->firstOrFail();

        $fields = $model->dynamicValues()
            ->with('field')
            ->get()
            ->pluck('field');

        $validator->validate(
            $fields->all(),
            $validated['values']
        );

        $service->save(
            $model,
            $validated['values']
        );

        return response()->json([
            'message' => 'Dynamic values saved successfully',
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show( string $model, string $id, DynamicValueService $service)
    {
        $modelClass = Relation::getMorphedModel($model);
        $model = app($modelClass)->where('uuid', $id)->firstOrFail();

        return ApiResponse::success($service->resolve($model));
    }
}
