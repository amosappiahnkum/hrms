<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Requests\StoreDynamicFieldRequest;
use App\Http\Requests\UpdateDynamicFieldRequest;
use App\Http\Resources\DynamicFieldResource;
use App\Http\Resources\DynamicFormResource;
use App\Models\DynamicField;
use App\Models\DynamicFieldOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DynamicFieldController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $fields = DynamicField::query()
            ->with('options')
            ->when(
                $request->filled('dynamic_form_id'),
                fn ($query) => $query->whereHas('form',
                    fn ($q) => $q
                        ->where('uuid', $request->dynamic_form_id)
                        ->when(
                            $request->filled('model'),
                            fn ($q) => $q->where('model', $request->model)
                        )
                )
            )
            ->latest()
            ->paginate();

        return DynamicFieldResource::collection($fields);
    }

    /**
     * Store a newly created resource in storage.
     * @throws Throwable
     */
    public function store(StoreDynamicFieldRequest $request)
    {
        DB::beginTransaction();

        try {

            $field = DynamicField::create($request->validated());

            if ($request->filled('options')) {

                foreach ($request->options as $option) {

                    DynamicFieldOption::create([

                        'dynamic_field_id' => $field->id,

                        'label' => $option['label'],

                        'value' => $option['value'],

                        'sort_order' => $option['sort_order'] ?? 0,
                    ]);
                }
            }

            DB::commit();
            return ApiResponse::success(DynamicFieldResource::make($field->load('options')));

        } catch (\Exception $e) {

            DB::rollBack();

            Log::error($e->getMessage());
            return ApiResponse::error('Failed to update dynamic form', []);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(DynamicField $dynamicField)
    {
        return ApiResponse::success(DynamicFieldResource::make($dynamicField->load('options')));
    }

    /**
     * Update the specified resource in storage.
     * @throws Throwable
     */
    public function update(UpdateDynamicFieldRequest $request, DynamicField $dynamicField)
    {
        DB::beginTransaction();

        try {
            $dynamicField->update($request->validated());

            if ($request->has('options')) {

                $dynamicField->options()->delete();

                foreach ($request->options as $option) {

                    DynamicFieldOption::create([
                        'dynamic_field_id' => $dynamicField->id,
                        'label' => $option['label'],
                        'value' => $option['value'],
                        'sort_order' => $option['sort_order'] ?? 0,
                    ]);
                }
            }

            DB::commit();

            return ApiResponse::success(DynamicFieldResource::make($dynamicField->load('options')));

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
    public function destroy(DynamicField $dynamicField)
    {
        DB::beginTransaction();

        try {
            $dynamicField->delete();

            DB::commit();

            return ApiResponse::success([], 'Dynamic field deleted successfully');
        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error($e->getMessage());
            return ApiResponse::error('Failed to delete', []);
        }
    }
}
