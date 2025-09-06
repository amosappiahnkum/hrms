<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Publication extends Model
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'title',
        'authors',
        'publication_date',
        'publisher',
        'edition',
        'volume_and_issue_number',
        'isbn_issn',
        'doi',
        'employee_id',
        'user_id'
    ];

    protected $casts = [
        'authors' => 'array',
        'publication_date' => 'date'
    ];

    // Relationship with user
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // New relationship
    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
