<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Second facteur d'un compte : secret chiffré, date de confirmation, dernier pas de temps accepté, empreintes des codes de secours.
 */
#[Hidden(['secret', 'recovery_codes'])]
class SecondFactor extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'confirmed_at' => 'datetime', 'recovery_codes' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
