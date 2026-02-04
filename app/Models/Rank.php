<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rank extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
      'name'
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
