<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class DocumentCategory extends Model
{
    use HasUuid;

    protected $fillable = ['name', 'description', 'parent_id'];

    public function policyDocuments()
    {
        return $this->hasMany(PolicyDocument::class);
    }

    public function parent()
    {
        return $this->belongsTo(DocumentCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(DocumentCategory::class, 'parent_id');
    }
}
