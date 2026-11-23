<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Invitation inconnue, expirée, révoquée ou déjà traitée : une seule réponse, sans détail.
 */
class InvalidInvitation extends RuntimeException
{
    public const MESSAGE = 'Cette invitation est invalide, expirée ou déjà utilisée. Demandez-en une nouvelle à la personne qui vous a invité.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
