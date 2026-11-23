<?php

namespace App\Http\Controllers;

use App\Services\ActiveContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContextController extends Controller
{
    /**
     * Le choix envoyé est vérifié contre les appartenances effectives du compte ; il ne prouve rien par lui-même.
     */
    public function update(Request $request, ActiveContext $context): RedirectResponse
    {
        $data = $request->validate(['membership_id' => ['nullable', 'integer']]);
        $membership = $context->switchTo($request, $data['membership_id'] ?? null);

        return redirect()->route('profile')->with('status', 'Contexte actif : '.$context->label($membership).'.');
    }
}
