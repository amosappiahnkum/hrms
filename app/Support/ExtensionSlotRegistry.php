<?php

namespace App\Support;

class ExtensionSlotRegistry
{
    private static array $slots = [

        // Employee core sections
        ['model' => 'Employee', 'modelClass' => 'App\\Models\\SelfService\\Employee', 'context' => 'profile'],
        ['model' => 'Employee', 'modelClass' => 'App\\Models\\SelfService\\Employee', 'context' => 'biography'],
        ['model' => 'Employee', 'modelClass' => 'App\\Models\\SelfService\\Employee', 'context' => 'contact'],
        ['model' => 'Employee', 'modelClass' => 'App\\Models\\SelfService\\Employee', 'context' => 'job_detail'],
        ['model' => 'Employee', 'modelClass' => 'App\\Models\\SelfService\\Employee', 'context' => 'next_of_kin'],
        ['model' => 'Employee', 'modelClass' => 'App\\Models\\SelfService\\Employee', 'context' => 'specializations'],
        ['model' => 'Employee', 'modelClass' => 'App\\Models\\SelfService\\Employee', 'context' => 'research_interests'],
        ['model' => 'Employee', 'modelClass' => 'App\\Models\\SelfService\\Employee', 'context' => 'onboarding'],

        // Self-service CRUD forms
        ['model' => 'Education',        'modelClass' => 'App\\Models\\SelfService\\Education',        'context' => 'form'],
        ['model' => 'Experience',       'modelClass' => 'App\\Models\\SelfService\\Experience',       'context' => 'form'],
        ['model' => 'Award',            'modelClass' => 'App\\Models\\SelfService\\Award',            'context' => 'form'],
        ['model' => 'Achievement',      'modelClass' => 'App\\Models\\SelfService\\Achievement',      'context' => 'form'],
        ['model' => 'Affiliation',      'modelClass' => 'App\\Models\\SelfService\\Affiliation',      'context' => 'form'],
        ['model' => 'Publication',      'modelClass' => 'App\\Models\\SelfService\\Publication',      'context' => 'form'],
        ['model' => 'Project',          'modelClass' => 'App\\Models\\SelfService\\Project',          'context' => 'form'],
        ['model' => 'GrantAndFund',     'modelClass' => 'App\\Models\\SelfService\\GrantAndFund',     'context' => 'form'],
        ['model' => 'EmergencyContact', 'modelClass' => 'App\\Models\\SelfService\\EmergencyContact', 'context' => 'form'],
        ['model' => 'Dependant',        'modelClass' => 'App\\Models\\SelfService\\Dependant',        'context' => 'form'],
        ['model' => 'CommunityService', 'modelClass' => 'App\\Models\\SelfService\\CommunityService', 'context' => 'form'],

        // Training history
        ['model' => 'PreviousRank',     'modelClass' => 'App\\Models\\Training\\PreviousRank',     'context' => 'form'],
        ['model' => 'PreviousPosition', 'modelClass' => 'App\\Models\\Training\\PreviousPosition', 'context' => 'form'],

        // Leave
        ['model' => 'LeaveRequest', 'modelClass' => 'App\\Models\\LeaveRequest', 'context' => 'form'],

        // Appraisal
        ['model' => 'Appraisal', 'modelClass' => 'App\\Models\\PerformanceAppraisal\\Appraisal', 'context' => 'form'],
        ['model' => 'Appraisal', 'modelClass' => 'App\\Models\\PerformanceAppraisal\\Appraisal', 'context' => 'self_review'],

        // Information updates
//        ['model' => 'InformationUpdate', 'modelClass' => 'App\\Models\\InformationUpdate', 'context' => 'form'],
    ];

    public static function all(): array
    {
        return self::$slots;
    }

    public static function models(): array
    {
        return collect(self::$slots)
            ->unique('model')
            ->map(fn($s) => [
                'model'      => $s['model'],
                'modelClass' => $s['modelClass'],
            ])
            ->values()
            ->all();
    }

    public static function contextsFor(string $model): array
    {
        return collect(self::$slots)
            ->where('model', $model)
            ->pluck('context')
            ->values()
            ->all();
    }

    public static function grouped(): array
    {
        return collect(self::$slots)
            ->groupBy('model')
            ->map(fn($slots) => [
                'modelClass' => $slots->first()['modelClass'],
                'contexts'   => $slots->pluck('context')->values()->all(),
            ])
            ->all();
    }
}
