<?php

namespace App\Models\Training;

use App\Models\QuestionBank\Question;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Quiz extends Model
{
    /** @use HasFactory<\Database\Factories\Training\QuizFactory> */
    use HasFactory;

    protected $fillable = ['title', 'description', 'time_limit'];

    /**
     * Questions attached to this quiz
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
