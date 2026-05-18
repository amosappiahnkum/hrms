<?php

namespace App\Http\Controllers\QuestionBank;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuestionOptionRequest;
use App\Http\Requests\UpdateQuestionOptionRequest;
use App\Http\Resources\QuestionOptionResource;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionOption;

class QuestionOptionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $options = QuestionOption::query()->with('question')->orderBy('order')->paginate();

        QuestionOptionResource::collection($options);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreQuestionOptionRequest $request)
    {
        $question = Question::findOrFail(
            $request->question_id
        );

        // 🔒 Prevent options on unsupported question types
        if (! in_array($question->type, ['multiple_choice', 'rating'])) {

            return response()->json([
                'message' => 'This question type does not support options.'
            ], 422);
        }

        $option = QuestionOption::create($request->validated());


        return ApiResponse::success(QuestionOptionResource::make($option));
    }

    /**
     * Display the specified resource.
     */
    public function show(QuestionOption $questionOption)
    {
        return ApiResponse::success(QuestionOptionResource::make($questionOption));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateQuestionOptionRequest $request, QuestionOption $questionOption)
    {
        $questionOption->update($request->validated());

        return ApiResponse::success(QuestionOptionResource::make($questionOption));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(QuestionOption $questionOption)
    {
        $questionOption->delete();

        return ApiResponse::success([], 'Question option deleted successfully.');
    }
}
