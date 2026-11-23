<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PersonalSpace;
use App\Models\User;
use App\Services\GoogleSignIn;
use App\Support\MinorsPathway;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        abort_unless(config('campus.identity.registration_enabled'), 404);

        return Inertia::render('auth/Register', ['minorsPathwayOpen' => MinorsPathway::isOpen(), 'googleEnabled' => app(GoogleSignIn::class)->enabled()]);
    }

    /**
     * Inscription individuelle : aucun rôle, établissement ou matricule n'est accepté ici.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('campus.identity.registration_enabled'), 404);
        $minorsOpen = MinorsPathway::isOpen();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'adult_declaration' => $minorsOpen ? ['boolean'] : ['accepted'],
        ], ['adult_declaration.accepted' => 'L’inscription des mineurs n’est pas encore ouverte : cette déclaration est nécessaire.']);

        $user = DB::transaction(function () use ($data, $request): User {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->forceFill([
                'adult_declared_at' => $request->boolean('adult_declaration') ? now() : null,
                'password_changed_at' => now(),
            ])->save();
            PersonalSpace::create(['owner_id' => $user->id]);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
