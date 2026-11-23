<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\PersonalSpace;
use App\Models\User;
use App\Support\MinorsPathway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountProvisioner
{
    /** Alphabet sans caractères ambigus (0/O, 1/I/L). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const IDENTITY_CHECKS = [
        'in_person_document' => 'En présence de la personne, avec une pièce ou carte scolaire',
        'in_person_known' => 'En présence de la personne, connue du personnel habilité',
    ];

    public function __construct(private ContextVisibility $visibility, private SessionRegistry $sessions) {}

    /**
     * Crée un compte sans adresse électronique, rattaché comme apprenant au périmètre de l'administrateur.
     *
     * @return array{user: User, secret: string}
     */
    public function provision(User $actor, int $adminMembershipId, string $name, string $localIdentifier, bool $adultAttested): array
    {
        $admin = $this->visibility->administeredMembership($actor, $adminMembershipId);
        $minorsOpen = MinorsPathway::isOpen();
        if (! $minorsOpen && ! $adultAttested) {
            throw ValidationException::withMessages(['adult_attested' => 'Le parcours des mineurs n’est pas ouvert : attestez que la personne n’en relève pas.']);
        }
        $localIdentifier = trim($localIdentifier);
        $duplicate = ['local_identifier' => 'Ce matricule est déjà rattaché dans cet établissement pour cette année.'];
        if (Membership::query()->where('institution_id', $admin->institution_id)->where('academic_year_id', $admin->academic_year_id)
            ->where('current_local_identifier', $localIdentifier)->exists()) {
            throw ValidationException::withMessages($duplicate);
        }
        $secret = $this->newSecret();

        try {
            $user = DB::transaction(function () use ($actor, $admin, $name, $localIdentifier, $secret, $adultAttested, $minorsOpen): User {
                $user = new User(['name' => $name, 'password' => Str::random(64)]);
                $user->forceFill([
                    'login_identifier' => $this->newIdentifier(),
                    'activation_secret_hash' => Hash::make($secret),
                    'activation_expires_at' => $this->expiry(),
                    'adult_declared_at' => $adultAttested ? now() : null,
                ])->save();
                PersonalSpace::create(['owner_id' => $user->id]);
                Membership::create([
                    'user_id' => $user->id, 'scope' => 'institution', 'institution_id' => $admin->institution_id,
                    'academic_year_id' => $admin->academic_year_id, 'role' => 'learner', 'status' => 'active',
                    'local_identifier' => $localIdentifier, 'starts_at' => now(),
                ]);
                AuditLog::record($actor, 'account.provisioned', $user, $admin->institution_id, null, [
                    'minors_pathway' => $minorsOpen ? 'open' : 'closed', 'adult_attested' => $adultAttested,
                ]);

                return $user;
            });
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw ValidationException::withMessages($duplicate);
            }
            throw $error;
        }

        return ['user' => $user, 'secret' => $this->display($secret)];
    }

    /**
     * Récupération assistée : nouveau secret personnel, ancien accès et sessions invalidés, trace sans secret.
     */
    public function issueRecoverySecret(User $actor, int $adminMembershipId, int $subjectId, string $identityCheck): array
    {
        $admin = $this->visibility->administeredMembership($actor, $adminMembershipId);
        if (! array_key_exists($identityCheck, self::IDENTITY_CHECKS)) {
            throw ValidationException::withMessages(['identity_check' => 'Choisissez le contrôle d’identité réalisé.']);
        }
        $subject = $this->visibility->provisionedLearner($admin, $subjectId);
        $secret = $this->newSecret();

        DB::transaction(function () use ($actor, $admin, $subject, $secret, $identityCheck): void {
            $subject->forceFill([
                'password' => Str::random(64),
                'remember_token' => Str::random(60),
                'activation_secret_hash' => Hash::make($secret),
                'activation_expires_at' => $this->expiry(),
                'activated_at' => null,
            ])->save();
            $this->sessions->revokeAll($subject);
            AuditLog::record($actor, 'account.assisted_recovery', $subject, $admin->institution_id, $identityCheck);
        });

        return ['user' => $subject, 'secret' => $this->display($secret)];
    }

    /**
     * Active le compte avec le secret temporaire et fixe en même temps le mot de passe personnel.
     */
    public function activate(string $identifier, string $secret, string $password): ?User
    {
        return DB::transaction(function () use ($identifier, $secret, $password): ?User {
            $user = User::query()->where('login_identifier', Str::upper(trim($identifier)))->whereNull('email')
                ->where('status', 'active')->lockForUpdate()->first();
            $hash = $user?->activation_secret_hash;
            $valid = Hash::check($this->canonical($secret), $hash ?? Hash::make(Str::random(16)));
            if (! $user || $hash === null || ! $valid || $user->activation_expires_at === null || $user->activation_expires_at->isPast()) {
                return null;
            }
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'activation_secret_hash' => null,
                'activation_expires_at' => null,
                'activated_at' => now(),
                'password_changed_at' => now(),
            ])->save();
            AuditLog::record($user, 'account.activated', $user);

            return $user;
        });
    }

    private function newSecret(): string
    {
        return $this->random(12);
    }

    private function newIdentifier(): string
    {
        do {
            $identifier = 'HK-'.$this->random(8);
        } while (User::query()->where('login_identifier', $identifier)->exists());

        return $identifier;
    }

    private function random(int $length): string
    {
        $value = '';
        for ($index = 0; $index < $length; $index++) {
            $value .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $value;
    }

    private function canonical(string $secret): string
    {
        return Str::upper(preg_replace('/[\s-]+/', '', $secret) ?? '');
    }

    private function display(string $secret): string
    {
        return implode('-', str_split($secret, 4));
    }

    private function expiry(): \DateTimeInterface
    {
        return now()->addHours(max(1, (int) config('campus.identity.activation_ttl_hours')));
    }
}
