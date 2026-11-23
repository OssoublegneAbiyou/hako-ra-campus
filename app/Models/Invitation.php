<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    public const PRIVILEGED_ROLES = ['teacher', 'institution_admin', 'catalogue_editor', 'platform_admin'];

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    /**
     * Seule l'empreinte du secret est stockée ; le secret lui-même n'est connu que du lien remis.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'pending')->where('expires_at', '>', now());
    }

    public function displayStatus(): string
    {
        return $this->status === 'pending' && $this->expires_at->isPast() ? 'expired' : $this->status;
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
