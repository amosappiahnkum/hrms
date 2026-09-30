<?php

namespace App\Models;

use App\Traits\RecordsActivity;
use App\Http\Traits\UsesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Photo extends Model
{
    use HasFactory, SoftDeletes, RecordsActivity;

    public function photoable(): MorphTo
    {
        return $this->morphTo();
    }

    protected $fillable = [
        'photoable',
        'file_name',
    ];

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
