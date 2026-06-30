<?php

namespace App\Models\Appraisal;

use App\Models\ApplicationModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class JobKpi extends ApplicationModel
{
    use HasUuid;

    protected $table = 'job_kpis';

    protected $fillable = [
        'department_id',
        'description',
        'description_hash',
        'created_by',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Config\Department::class);
    }

    /**
     * Upsert a KPI description for a department, returning the record.
     * Skips insert if an identical description (case-insensitive) already exists for that dept.
     */
    public static function upsertForDepartment(int $departmentId, string $description, ?int $createdBy = null): self
    {
        $hash = hash('sha256', strtolower(trim($description)));

        return static::firstOrCreate(
            ['department_id' => $departmentId, 'description_hash' => $hash],
            ['description' => trim($description), 'created_by' => $createdBy],
        );
    }
}
