<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce jointe privée d'une tâche, d'un devoir ou d'un retour de cours. Le fichier est stocké hors du dossier public,
 * sous un nom aléatoire sans rapport avec le nom affiché, et n'est diffusé qu'une fois déclaré sain par l'antivirus.
 */
class Attachment extends Model
{
    use HasUuids;

    public const SCAN_LABELS = ['quarantine' => 'En quarantaine, en attente d’analyse', 'scanning' => 'Analyse antivirus en cours', 'clean' => 'Analysé, disponible',
        'rejected' => 'Refusé par l’antivirus', 'error' => 'Analyse impossible pour l’instant : fichier non diffusé'];

    protected $guarded = ['id'];

    protected $hidden = ['stored_name'];

    protected $attributes = ['scan_status' => 'quarantine', 'scan_attempts' => 0];

    protected function casts(): array
    {
        return ['scan_started_at' => 'immutable_datetime', 'scanned_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime', 'file_removed_at' => 'immutable_datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CourseCheckIn::class, 'course_check_in_id');
    }

    public function path(): string
    {
        return 'attachments/'.$this->stored_name;
    }
}
