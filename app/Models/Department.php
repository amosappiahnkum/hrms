<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends ApplicationModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
      'name', 'user_id'
    ];

    protected $casts = [
      'id' => 'integer'
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
