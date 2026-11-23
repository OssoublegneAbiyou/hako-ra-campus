<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'activation_secret_hash'])]
class User extends Authenticatable implements MustVerifyEmail
{
    protected $attributes = ['status' => 'active'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activation_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'adult_declared_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'erased_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Compte créé par un établissement, sans adresse électronique individuelle.
     */
    public function isProvisioned(): bool
    {
        return $this->email === null;
    }

    /**
     * Un compte provisionné est vérifié par son activation personnelle, pas par courriel.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->isProvisioned() ? $this->activated_at !== null : $this->email_verified_at !== null;
    }

    public function hasPrivilegedRole(): bool
    {
        return $this->memberships()->effective()
            ->whereIn('role', ['teacher', 'institution_admin', 'catalogue_editor', 'platform_admin'])->exists();
    }

    public function secondFactor(): HasOne
    {
        return $this->hasOne(SecondFactor::class);
    }

    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    public function personalSpace(): HasOne
    {
        return $this->hasOne(PersonalSpace::class, 'owner_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
