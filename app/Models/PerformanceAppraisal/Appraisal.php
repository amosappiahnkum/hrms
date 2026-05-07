<?php

namespace App\Models\PerformanceAppraisal;

use App\Models\QuestionBank\Question;
use Database\Factories\PerformanceAppraisal\AppraisalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Appraisal extends Model
{
    /** @use HasFactory<AppraisalFactory> */
    use HasFactory;

    protected $fillable = ['title', 'description', 'status'];

    /**
     * Questions attached to this appraisal
     */
    public function questions(): MorphToMany
    {
        return $this->morphToMany(
            Question::class,
            'usable',
            'question_usages'
        )->withPivot(['order', 'custom_weight', 'is_required_override'])
            ->withTimestamps()
            ->orderByPivot('order');
    }
}
