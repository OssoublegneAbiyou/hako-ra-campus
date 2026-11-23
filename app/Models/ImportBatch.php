<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot d'import du planning : un fichier, une empreinte, un périmètre. Appliqué en entier ou pas du tout.
 */
class ImportBatch extends Model
{
    use HasUuids;

    public const STATUS_LABELS = [
        'uploaded' => 'Déposé, analyse en attente', 'analyzing' => 'Analyse en cours', 'invalid' => 'Invalide : corrections nécessaires',
        'valid' => 'Valide : prêt à être appliqué au brouillon', 'applying' => 'Application au brouillon en cours',
        'applied' => 'Appliqué au brouillon', 'failed' => 'Échec du traitement',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['parsed_rows', 'stored_path'];

    protected function casts(): array
    {
        return ['counts' => 'array', 'analyzed_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime', 'raw_purged_at' => 'immutable_datetime'];
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ImportIssue::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
