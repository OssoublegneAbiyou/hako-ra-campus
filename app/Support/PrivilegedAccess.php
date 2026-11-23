<?php

namespace App\Support;

use App\Models\User;
use App\Services\SecondFactors;

class PrivilegedAccess
{
    /**
     * Les actions d'enseignement, d'administration et d'édition exigent un second facteur actif sur le compte (IAM07) et présenté
     * dans la session en cours. Dans un traitement de fond, sans session, le second facteur actif du compte agissant suffit : le
     * traitement a été lancé depuis une session vérifiée et revérifie ses droits par ailleurs.
     * La dérogation sans second facteur n'existe qu'en local et en test, pour travailler sur des données fictives.
     */
    public static function available(?User $user = null): bool
    {
        if (self::waived()) {
            return true;
        }
        $user ??= auth()->user();
        if (! $user instanceof User || $user->secondFactor?->isConfirmed() !== true) {
            return false;
        }
        $request = request();

        return $request->hasSession() ? app(SecondFactors::class)->sessionVerified($request, $user) : app()->runningInConsole();
    }

    public static function waived(): bool
    {
        return app()->environment(['local', 'testing']) && config('campus.identity.privileged_without_mfa') === true;
    }
}
