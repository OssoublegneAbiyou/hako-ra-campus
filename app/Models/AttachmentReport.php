<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signalement d'une pièce jointe partagée avec un groupe, traité par l'administration de l'établissement.
 */
class AttachmentReport extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open', 'open_slot' => 1];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
