<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseOccurrence extends Model
{
    use HasFactory,HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['original_date' => 'immutable_date', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'is_exception' => 'boolean'];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CourseOccurrenceRevision::class, 'course_occurrence_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'course_occurrence_group')->withPivot(['institution_id', 'academic_year_id']);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(CourseSeries::class, 'course_series_id');
    }
}
