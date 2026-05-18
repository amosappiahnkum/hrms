<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreDynamicFormRequest;
use App\Http\Requests\UpdateDynamicFormRequest;
use App\Http\Resources\DynamicFormResource;
use App\Http\Resources\DynamicFormSchemaResource;
use App\Models\DynamicForm;
use App\Services\DynamicFormResolverService;
use App\Support\ExtensionSlotRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DynamicFormController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $forms = DynamicForm::query()
            ->withCount('fields')
            ->latest()
            ->paginate();

        return DynamicFormResource::collection($forms);
    }

    /**
     * Store a newly created resource in storage.
     * @throws Throwable
     */
    public function store(StoreDynamicFormRequest $request)
    {
        DB::beginTransaction();

        try {

            $form = DynamicForm::create($request->validated());

            DB::commit();

            return ApiResponse::success(DynamicFormResource::make($form));

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error($e->getMessage());
            return ApiResponse::error('Failed to create dynamic form', []);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(DynamicForm $dynamicForm)
    {
        return ApiResponse::success(DynamicFormResource::make($dynamicForm));
    }

    /**
     * Update the specified resource in storage.
     * @throws Throwable
     */
    public function update(UpdateDynamicFormRequest $request, DynamicForm $dynamicForm)
    {
        DB::beginTransaction();

        try {

            $dynamicForm->update($request->validated());

            DB::commit();

            return ApiResponse::success(DynamicFormResource::make($dynamicForm));

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error($e->getMessage());
            return ApiResponse::error('Failed to update dynamic form', []);
        }
    }

    /**
     * Remove the specified resource from storage.
     * @throws Throwable
     */
    public function destroy(DynamicForm $dynamicForm)
    {
        DB::beginTransaction();

        try {

            $dynamicForm->delete();

            DB::commit();

            return ApiResponse::success([], 'Dynamic form deleted successfully');

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error($e->getMessage());
            return ApiResponse::error('Failed to update dynamic form', []);
        }
    }


    public function extension(
        string $model,
        string $context,
        DynamicFormResolverService $resolver
    ): JsonResponse {

        $form = $resolver->resolveExtension(
            $model,
            $context
        );

        return response()->json(['data' => $form ? new DynamicFormSchemaResource($form) : null,]);
    }

    public function slots(): JsonResponse
    {
        return ApiResponse::success(ExtensionSlotRegistry::grouped());
        /*return response()->json([
            'data' => [
                'models'  => ExtensionSlotRegistry::models(),
                'grouped' => ExtensionSlotRegistry::grouped(),
                'slots'   => ExtensionSlotRegistry::all(),
            ],
        ]);*/
    }

    public function schema(string $uuid, DynamicFormResolverService $resolver): JsonResponse {

        $form = $resolver->resolveByUuid(
            $uuid
        );

        abort_if(!$form, 404);

        return response()->json([

            'data' => new DynamicFormSchemaResource(
                $form
            ),
        ]);
    }
}
