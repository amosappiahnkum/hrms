<?php

use App\Http\Controllers\DynamicFieldController;
use App\Http\Controllers\DynamicFieldValueController;
use App\Http\Controllers\DynamicFormController;

Route::middleware('feature:dynamic_forms.enabled')->group(function () {
    Route::get('dynamic-forms/slots', [DynamicFormController::class, 'slots']);
    Route::get('dynamic-forms/extensions/{model}/{context}', [DynamicFormController::class, 'extension']);
    Route::get('dynamic-forms/{uuid}/schema', [DynamicFormController::class, 'schema']);
    Route::apiResource('dynamic-forms', DynamicFormController::class);
    Route::apiResource('dynamic-fields', DynamicFieldController::class);
    Route::post('dynamic-values', [DynamicFieldValueController::class, 'store']);
    Route::get('dynamic-values/{model}/{id}', [DynamicFieldValueController::class, 'show']);
});
