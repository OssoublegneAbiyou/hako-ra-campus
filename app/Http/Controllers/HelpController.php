<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Aide et support : page publique, sans donnée de compte. L'adresse de support n'est affichée que si elle est configurée ;
 * sinon la page le dit, sans inventer de contact.
 */
class HelpController extends Controller
{
    public function __invoke(): Response
    {
        $email = config('campus.support.email');

        return Inertia::render('Help', [
            'supportEmail' => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'offlineTtlDays' => max(1, (int) config('campus.offline.ttl_days')),
        ]);
    }
}
