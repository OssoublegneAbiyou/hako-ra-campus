<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Version publiée du planning d'un établissement pour une année. Immuable après création.
 */
class SchedulePublication extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'published_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Une version publiée du planning est immuable.'));
        static::deleting(fn () => throw new LogicException('Une version publiée du planning ne se supprime pas.'));
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SchedulePublicationChange::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
