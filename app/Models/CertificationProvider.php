<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificationProvider extends Model
{
    protected $fillable = ['name'];

    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class);
    }
}
