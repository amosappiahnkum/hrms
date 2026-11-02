<?php

namespace App\Models\Config;

use App\Models\ApplicationModel;
use App\Models\SelfService\Employee;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends ApplicationModel
{
    use HasFactory, SoftDeletes, HasUuid;

    protected $fillable = [
        'name', 'user_id', 'hod', 'parent_department_id', 'level',
    ];

    protected $casts = [
        'id' => 'integer',
        'level' => 'integer',
    ];

    /** The level names, top first (setting `org.department_levels`). */
    public static function levels(): array
    {
        return array_values(array_filter(array_map('strval', (array) setting('org.department_levels', ['Department'])), 'strlen'));
    }

    /** This department's level name, e.g. "Section"; null when it has none. */
    public function levelName(): ?string
    {
        return $this->level !== null ? (self::levels()[$this->level] ?? null) : null;
    }

    public function headOfDepartment(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hod');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_department_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_department_id');
    }

    /** Parents up to the top level, top level first. Stops at a loop in bad data. */
    public function ancestors(): \Illuminate\Support\Collection
    {
        $path = collect();
        $current = $this->parent;

        while ($current && !$path->contains('id', $current->id) && $current->id !== $this->id) {
            $path->prepend($current);
            $current = $current->parent;
        }

        return $path;
    }

    /**
     * These departments and every department below them (ids). "Department X" in filters and reports means its
     * sub-departments (sections, units) too.
     */
    public static function subtreeIds(int|array|null $ids): array
    {
        $all = array_values(array_unique(array_map('intval', array_filter((array) $ids))));
        for ($level = $all; $level; ) {
            $level = self::whereIn('parent_department_id', $level)->whereNotIn('id', $all)->pluck('id')->all();
            $all = array_merge($all, $level);
        }

        return $all;
    }

    /** The department given by uuid and everything below it; [0] (matches nothing) when there's no such department. */
    public static function subtreeIdsByUuid(?string $uuid): array
    {
        $id = $uuid ? self::where('uuid', $uuid)->value('id') : null;

        return $id ? self::subtreeIds($id) : [0];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
