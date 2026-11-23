<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\UserNotification;
use App\Services\ActiveContext;
use App\Services\OfflineSnapshot;
use App\Services\SecondFactors;
use App\Support\AppVersion;
use App\Support\PrivacyNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $development = app()->environment(['local', 'testing']);
        $items = [
            ['key' => 'home', 'label' => 'Accueil', 'route' => 'home'],
            ['key' => 'planning', 'label' => 'Planning', 'route' => 'planning'],
            ['key' => 'tasks', 'label' => 'Tâches', 'route' => 'tasks'],
            ['key' => 'opportunities', 'label' => 'Opportunités', 'route' => 'opportunities'],
            ['key' => 'profile', 'label' => 'Profil', 'route' => 'profile'],
            ['key' => 'grades', 'label' => 'Notes', 'route' => 'grades'],
        ];
        $navigation = array_map(function (array $item) use ($development): array {
            $ready = in_array($item['key'], ['home', 'profile', 'planning', 'tasks', 'grades', 'opportunities'], true);

            return [
                'key' => $item['key'],
                'label' => $item['label'],
                'href' => ($ready || $development) ? route($item['route'], absolute: false) : null,
                'pending' => ! $ready,
            ];
        }, $items);
        $administration = [];
        foreach ([
            ['permission' => 'manage-institution', 'route' => 'admin.institution', 'label' => 'Établissement'],
            ['permission' => 'edit-catalogue', 'route' => 'admin.catalogue', 'label' => 'Catalogue'],
            ['permission' => 'manage-platform', 'route' => 'admin.platform', 'label' => 'Plateforme'],
        ] as $entry) {
            if (Route::has($entry['route']) && Gate::allows($entry['permission'])) {
                $administration[] = ['label' => $entry['label'], 'href' => route($entry['route'], absolute: false)];
            }
        }

        $user = $request->user();
        $contexts = app(ActiveContext::class);
        // Mot de passe accepté mais second facteur pas encore présenté : la mise en page ne montre ni contexte ni appartenance.
        $awaitingSecondFactor = $user !== null && $user->secondFactor?->isConfirmed() === true && ! app(SecondFactors::class)->sessionVerified($request, $user);
        $active = $awaitingSecondFactor ? null : $contexts->current($request);
        $flash = fn (string $key) => fn () => $request->hasSession() ? $request->session()->get($key) : null;

        return [
            ...parent::share($request),
            // accountKey : identifiant opaque du compte, pour que l'appareil efface ses données locales si un autre compte s'y connecte.
            'auth' => ['user' => $user ? ['name' => $user->name] : null, 'accountKey' => $user && $user->status === 'active' && ! $awaitingSecondFactor ? OfflineSnapshot::accountKey($user) : null],
            'flash' => [
                'status' => $flash('status'),
                // Action refusée ou session expirée sur un écran qui n'affiche pas lui-même l'erreur.
                'problem' => $flash('problem'),
                'oneTimeSecret' => $flash('oneTimeSecret'),
                'oneTimeLink' => $flash('oneTimeLink'),
            ],
            'campus' => [
                'development' => $development,
                // Version en service, affichée en pied de page.
                'version' => AppVersion::current(),
                'context' => [
                    'kind' => $active?->scope ?? 'individual',
                    'label' => $contexts->label($active),
                    'description' => match (true) {
                        $active !== null => Membership::ROLE_LABELS[$active->role],
                        $user !== null => 'Connecté : '.$user->name,
                        default => 'Visite sans connexion',
                    },
                    'membershipId' => $active?->id,
                ],
                'contexts' => $user && $user->status === 'active' && $user->hasVerifiedEmail() && ! $awaitingSecondFactor
                    ? $contexts->available($user)->map(fn (Membership $membership): array => [
                        'membershipId' => $membership->id,
                        'label' => $contexts->label($membership).' — '.Membership::ROLE_LABELS[$membership->role]
                            .($membership->academicYear ? ' ('.$membership->academicYear->name.')' : ''),
                    ])->all()
                    : [],
                'navigation' => $navigation,
                // Compteur du centre interne, pour le seul compte connecté et vérifié.
                'unreadNotifications' => $user && $user->status === 'active' && $user->hasVerifiedEmail()
                    ? UserNotification::query()->where('user_id', $user->id)->where('status', 'delivered')->whereNull('read_at')->count() : null,
                'administration' => $administration,
                // Notice en vigueur pas encore lue par ce compte : rappel non bloquant.
                'privacyNoticePending' => $user && $user->status === 'active' && $user->hasVerifiedEmail()
                    && ! DB::table('privacy_acknowledgements')->where('user_id', $user->id)->where('notice_version', PrivacyNotice::current()['version'])->exists(),
            ],
        ];
    }
}
