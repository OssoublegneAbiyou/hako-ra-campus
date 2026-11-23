<?php

namespace App\Support;

class MinorsPathway
{
    /**
     * Le parcours n'est ouvert que si l'activation ET la référence du cadre validé sont renseignées.
     */
    public static function isOpen(): bool
    {
        return config('campus.minors.enabled') === true
            && is_string(config('campus.minors.framework_reference'))
            && trim(config('campus.minors.framework_reference')) !== '';
    }
}
