<?php

namespace App\Models;

use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificationProvider extends Model
{
    use RecordsActivity;

    protected $fillable = ['name'];

    public function certifications(): HasMany
    {
        return $this->hasMany(EmployeeCertification::class);
    }
}
