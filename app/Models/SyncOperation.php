<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Accusé d'une opération synchronisée depuis un appareil. Aucun contenu saisi n'est conservé ici.
 */
class SyncOperation extends Model
{
    public const TYPES = ['task.create', 'task.update', 'check_in.save'];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['errors' => 'array', 'created_at' => 'immutable_datetime', 'base_version' => 'integer', 'result_version' => 'integer'];
    }
}
