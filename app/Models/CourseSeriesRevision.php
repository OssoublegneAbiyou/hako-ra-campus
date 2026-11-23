<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CourseSeriesRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'effective_from' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une révision de planning ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une révision de planning ne se supprime pas.'));
    }
}
