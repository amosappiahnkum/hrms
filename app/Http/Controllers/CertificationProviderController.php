<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Http\Resources\CertificationProviderResource;
use App\Models\CertificationProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CertificationProviderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $providers = CertificationProvider::query()
            ->when($request->search, fn($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->orderBy('name')
            ->get();

        return CertificationProviderResource::collection($providers);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->isHrAdmin(), 403, 'Unauthorized.');

        $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:certification_providers,name'],
        ]);

        $provider = CertificationProvider::create(['name' => $request->name]);

        return ApiResponse::success(CertificationProviderResource::make($provider), 'Provider created.', 201);
    }
}
