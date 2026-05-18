<?php

namespace App\Http\Controllers;

use App\Services\SettingService;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function __construct(private readonly SettingService $settings) {}

    public function overview(): JsonResponse
    {
        $company = $this->settings->module('company');

        return response()->json([
            'data' => [
                'name'         => $company['name'] ?? null,
                'abbreviation' => $company['abbreviation'] ?? null,
                'tagline'      => $company['tagline'] ?? null,
                'founded'      => $company['founded'] ?? null,
                'logo_url'     => $company['logo_url'] ?? null,
                'about'        => $company['about'] ?? null,
                'mission'      => $company['mission'] ?? null,
                'vision'       => $company['vision'] ?? null,
                'core_values'  => $company['core_values'] ?? [],
                'stats'        => $company['stats'] ?? [],
                'contact'      => $company['contact'] ?? [
                    'address' => null,
                    'phone'   => null,
                    'email'   => null,
                    'website' => null,
                ],
            ],
        ]);
    }
}
