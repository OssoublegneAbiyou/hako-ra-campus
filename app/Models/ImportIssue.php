<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ligne du rapport d'import : feuille, ligne, colonne, gravité et cause.
 */
class ImportIssue extends Model
{
    public const SEVERITY_LABELS = ['error' => 'Erreur bloquante', 'conflict' => 'Conflit à confirmer', 'warning' => 'Avertissement'];

    public $timestamps = false;

    protected $guarded = ['id'];
}
