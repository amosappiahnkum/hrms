<?php

namespace App\Models\Competency;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One competency in an assessment: what was required and what the employee showed. */
class CompetencyRating extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['competency_assessment_id', 'competency_id', 'required_level', 'level', 'evidence'];

    protected $casts = ['required_level' => 'integer', 'level' => 'integer'];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CompetencyAssessment::class, 'competency_assessment_id');
    }

    /** Files evidencing competence. */
    public function evidenceFiles(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(CompetencyEvidenceFile::class, 'evidenceable')->oldest('id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(Competency::class);
    }

    /** How many levels short of the requirement (0 when met, not required or not rated). */
    public function gap(): int
    {
        if ($this->required_level === null || $this->level === null) {
            return 0;
        }

        return max(0, $this->required_level - $this->level);
    }
}
