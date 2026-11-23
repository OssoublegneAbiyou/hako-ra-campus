<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Fichier ou lot d'import refusé en entier, avec un message destiné à l'utilisateur.
 */
class ImportRejected extends RuntimeException
{
    public function __construct(string $message, public ?int $sheetLine = null, public ?string $columnName = null)
    {
        parent::__construct($message);
    }
}
