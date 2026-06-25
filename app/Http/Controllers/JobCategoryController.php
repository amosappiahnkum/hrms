<?php

namespace App\Http\Controllers;

use App\Models\JobCategory;
use Illuminate\Http\JsonResponse;

class JobCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => JobCategory::select('id', 'uuid', 'name')->orderBy('name')->get(),
        ]);
    }
}
