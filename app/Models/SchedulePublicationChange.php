<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Différence d'une occurrence dans une version publiée : état avant, état après. Immuable.
 */
class SchedulePublicationChange extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('L’historique des publications est immuable.'));
        static::deleting(fn () => throw new LogicException('L’historique des publications ne se supprime pas.'));
    }
}
