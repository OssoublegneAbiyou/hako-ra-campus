<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Fiche d'un export de données personnelles : fichier privé, propre à un compte, disponible pendant une durée limitée.
 */
class DataExport extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'last_downloaded_at' => 'immutable_datetime', 'file_removed_at' => 'immutable_datetime'];
    }
}
