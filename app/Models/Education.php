<?php

namespace App\Models;

use App\Enums\CertificateType;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Education extends ApplicationModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'employee_id',
        'education_level_id',
        'institution',
        'qualification',
        'date',
        'type',
        'cert_number',
        'user_id'
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'education_level_id' => 'integer',
        'type' => CertificateType::class
    ];

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
        return $this->morphOne(Photo::class,'photoable');
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
}
