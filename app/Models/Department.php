<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends ApplicationModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
      'name', 'user_id', 'hod'
    ];

    protected $casts = [
      'id' => 'integer'
    ];

    public function headOfDepartment(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hod');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
