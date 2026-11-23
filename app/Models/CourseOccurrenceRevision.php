<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class CourseOccurrenceRevision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une révision de planning ne se modifie pas.'));
        static::deleting(fn () => throw new LogicException('Une révision de planning ne se supprime pas.'));
    }
}
