<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SecondFactor;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Second facteur des comptes : activation en deux temps (secret, puis confirmation par un code), vérification à la connexion,
 * codes de secours à usage unique, retrait. Aucun secret ni code n'est écrit dans l'audit ou les journaux.
 */
class SecondFactors
{
    public const SESSION_KEY = 'campus.second_factor';

    public const RECOVERY_CODES = 8;

    /**
     * Prépare un secret non confirmé. Tant qu'il n'est pas confirmé par un code, il ne protège ni n'ouvre rien.
     */
    public function begin(User $user): SecondFactor
    {
        $factor = $user->secondFactor;
        abort_if($factor?->isConfirmed(), 409, 'Un second facteur est déjà actif sur ce compte.');
        // La relation déjà chargée sur ce compte ne doit pas survivre à une écriture.
        $user->unsetRelation('secondFactor');

        return SecondFactor::updateOrCreate(['user_id' => $user->id], ['secret' => Totp::generateSecret(), 'confirmed_at' => null, 'last_used_step' => null, 'recovery_codes' => null]);
    }

    /**
     * Confirme le secret par un premier code. Rend les codes de secours en clair, une seule fois.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code): ?array
    {
        $user->unsetRelation('secondFactor');

        return DB::transaction(function () use ($user, $code): ?array {
            $factor = SecondFactor::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($factor === null || $factor->isConfirmed()) {
                return null;
            }
            $step = Totp::match($factor->secret, $code);
            if ($step === null) {
                return null;
            }
            $codes = $this->newRecoveryCodes();
            $factor->forceFill(['confirmed_at' => now(), 'last_used_step' => $step, 'recovery_codes' => $this->hashed($codes)])->save();
            AuditLog::record($user, 'second_factor.enabled', $user);

            return $codes;
        });
    }

    /**
     * Vérifie un code de l'application, ou un code de secours (consommé). Rend 'totp', 'recovery' ou null.
     */
    public function verify(User $user, string $input): ?string
    {
        $user->unsetRelation('secondFactor');

        return DB::transaction(function () use ($user, $input): ?string {
            $factor = SecondFactor::query()->where('user_id', $user->id)->whereNotNull('confirmed_at')->lockForUpdate()->first();
            if ($factor === null) {
                return null;
            }
            $step = Totp::match($factor->secret, $input, $factor->last_used_step);
            if ($step !== null) {
                $factor->forceFill(['last_used_step' => $step])->save();

                return 'totp';
            }
            $candidate = Str::upper(preg_replace('/[\s-]/', '', $input) ?? '');
            if (strlen($candidate) !== 10) {
                return null;
            }
            $remaining = $factor->recovery_codes ?? [];
            foreach ($remaining as $index => $hash) {
                if (Hash::check($candidate, $hash)) {
                    unset($remaining[$index]);
                    $factor->forceFill(['recovery_codes' => array_values($remaining)])->save();
                    AuditLog::record($user, 'second_factor.recovery_code_used', $user, null, null, ['remaining' => count($remaining)]);

                    return 'recovery';
                }
            }

            return null;
        });
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $factor = $user->secondFactor;
        abort_unless($factor?->isConfirmed() === true, 409);
        $codes = $this->newRecoveryCodes();
        $factor->forceFill(['recovery_codes' => $this->hashed($codes)])->save();
        $user->unsetRelation('secondFactor');
        AuditLog::record($user, 'second_factor.recovery_codes_regenerated', $user);

        return $codes;
    }

    /**
     * Retrait. $actor est la personne elle-même, ou null pour un retrait par l'exploitation (commande), toujours tracé.
     */
    public function remove(User $user, ?User $actor, ?string $reason = null): bool
    {
        $deleted = SecondFactor::query()->where('user_id', $user->id)->delete() > 0;
        $user->unsetRelation('secondFactor');
        if ($deleted) {
            AuditLog::record($actor, $actor === null ? 'second_factor.reset_by_operator' : 'second_factor.disabled', $user, null, $reason);
        }

        return $deleted;
    }

    public function markVerified(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, ['user' => $user->id, 'at' => now()->unix()]);
    }

    /**
     * La session courante a-t-elle présenté le second facteur de ce compte ?
     */
    public function sessionVerified(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }
        $marker = $request->session()->get(self::SESSION_KEY);

        return is_array($marker) && ($marker['user'] ?? null) === $user->id;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->secondFactor?->recovery_codes ?? []);
    }

    /**
     * Dix caractères sans lettres ni chiffres ambigus, affichés en deux groupes de cinq.
     *
     * @return list<string>
     */
    private function newRecoveryCodes(): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $code = '';
            for ($j = 0; $j < 10; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($code, 0, 5).'-'.substr($code, 5);
        }

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function hashed(array $codes): array
    {
        return array_map(fn (string $code): string => Hash::make(str_replace('-', '', $code)), $codes);
    }
}
