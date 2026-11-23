<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseSeries extends Model
{
    use HasFactory,HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CourseSeriesRevision::class, 'course_series_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(CourseOccurrence::class, 'course_series_id');
    }
}
