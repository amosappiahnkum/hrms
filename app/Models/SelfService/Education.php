<?php

namespace App\Models\SelfService;

use App\Enums\CertificateType;
use App\Models\AppModel;
use App\Models\Config\EducationLevel;
use App\Models\InformationUpdate;
use App\Models\Photo;
use App\Traits\HasApprovalUpdates;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Education extends AppModel
{
    use HasFactory, SoftDeletes, HasUuid, HasApprovalUpdates;

    protected $fillable = [
        'employee_id',
        'education_level_id',
        'institution',
        'qualification',
        'date',
        'type',
        'cert_number',
        'field',
        'country',
        'user_id',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'education_level_id' => 'integer',
        'type' => CertificateType::class
    ];

    public function approvableFields(): array
    {
        return [
            'employee_id' => ['show_in_diff' => true],
            'education_level_id' => ['show_in_diff' => false],
            'institution' => ['show_in_diff' => false],
            'qualification' => ['show_in_diff' => false],
            'date' => ['show_in_diff' => false],
            'type' => ['show_in_diff' => false],
            'cert_number' => ['show_in_diff' => false],
            'field' => ['show_in_diff' => false],
            'country' => ['show_in_diff' => false],
            'user_id' => ['show_in_diff' => true],
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class);
    }

    public function photo(): MorphOne
    {
        return $this->morphOne(Photo::class, 'photoable');
    }

    /**
     * @return MorphOne
     */
    public function informationUpdate(): MorphOne
    {
        return $this->morphOne(InformationUpdate::class, 'information')
            ->where('status', 'pending')
            ->latest();
    }

    protected static function booted()
    {
        parent::booted();
        static::saving(function ($qualification) {
            if ($qualification->education_level_id) {
                $qualification->education_level_rank =
                    $qualification->level()->value('rank');
            }
        });
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id');
    }
}
