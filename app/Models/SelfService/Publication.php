<?php

namespace App\Models\SelfService;

use App\Models\AppModel;
use App\Models\User;
use App\Traits\HasApprovalUpdates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Publication extends AppModel
{
    use HasFactory, SoftDeletes, HasApprovalUpdates;

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
        'user_id',
        'type'
    ];

    public function approvableFields(): array
    {
        return [
            'title' => ['show_in_diff' => true],
            'publication_date' => ['show_in_diff' => true],
            'publisher' => ['show_in_diff' => true],
            'edition' => ['show_in_diff' => true],
            'volume_and_issue_number' => ['show_in_diff' => true],
            'isbn_issn' => ['show_in_diff' => true],
            'doi' => ['show_in_diff' => true],
            'type' => ['show_in_diff' => true],
            'employee_id' => ['show_in_diff' => false],
            'user_id' => ['show_in_diff' => false],
        ];
    }

    protected $casts = [
        'authors' => 'array',
        'publication_date' => 'date'
    ];

    // Relationship with user
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // New relationship
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
