<?php

namespace App\Models;

use App\Models\SelfService\Employee;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class PolicyDocument extends ApplicationModel
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'category',
        'file_path',
        'preview_path',
        'file_name',
        'file_size',
        'mime_type',
        'is_downloadable',
        'scope_type',
        'scope_ids',
        'is_active',
        'user_id',
    ];

    protected $casts = [
        'scope_ids'       => 'array',
        'is_downloadable' => 'boolean',
        'is_active'       => 'boolean',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Generate a short-lived signed URL. Inline for view-only, attachment for download. */
    public function temporaryUrl(bool $forDownload = false): string
    {
        $disposition = $forDownload && $this->is_downloadable
            ? 'attachment; filename="' . $this->file_name . '"'
            : 'inline';

        return Storage::disk('s3')->temporaryUrl(
            $this->file_path,
            now()->addMinutes(5),
            [
                'ResponseContentDisposition' => $disposition,
                'ResponseContentType'        => $this->mime_type,
            ]
        );
    }

    /** Check if an employee can access this document. */
    public function isAccessibleBy(Employee $employee): bool
    {
        if (!$this->is_active) return false;

        return match ($this->scope_type) {
            'all'          => true,
            'department'   => in_array($employee->department_id, $this->scope_ids ?? []),
            'job_category' => in_array(
                $employee->jobDetail?->job_category_id,
                $this->scope_ids ?? []
            ),
            'role'         => $this->checkRoleAccess($employee),
            default        => false,
        };
    }

    private function checkRoleAccess(Employee $employee): bool
    {
        $roles = $this->scope_ids ?? [];

        if (in_array('hod', $roles)) {
            $isHod = $employee->department?->hod === $employee->id;
            if ($isHod) return true;
        }

        return false;
    }

    /** Scope: documents visible to a given employee. */
    public function scopeAccessibleBy($query, Employee $employee)
    {
        $deptId        = $employee->department_id;
        $jobCategoryId = $employee->jobDetail?->job_category_id;
        $isHod         = $employee->department?->hod === $employee->id;

        return $query->where('is_active', true)->where(function ($q) use ($deptId, $jobCategoryId, $isHod) {
            $q->where('scope_type', 'all')
              ->orWhere(function ($q) use ($deptId) {
                  $q->where('scope_type', 'department')
                    ->whereJsonContains('scope_ids', $deptId);
              })
              ->orWhere(function ($q) use ($jobCategoryId) {
                  $q->where('scope_type', 'job_category')
                    ->whereJsonContains('scope_ids', $jobCategoryId);
              });

            if ($isHod) {
                $q->orWhere(function ($q) {
                    $q->where('scope_type', 'role')
                      ->whereJsonContains('scope_ids', 'hod');
                });
            }
        });
    }
}
