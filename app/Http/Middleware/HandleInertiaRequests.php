<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Models\PersonalPeriod;
use App\Models\PersonalSubject;
use App\Models\User;
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
use Inertia\Inertia;
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
        $member = $user !== null && $user->status === 'active' && $user->hasVerifiedEmail() && ! $awaitingSecondFactor;
        $available = $member ? $contexts->available($user) : collect();
        // Nom court d'un contexte, pour les pastilles : le rôle n'est ajouté que si deux appartenances portent le même nom.
        $sameName = $available->countBy(fn (Membership $membership): string => $contexts->label($membership));

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
            // Données de l'ajout rapide : chargées seulement à l'ouverture du panneau, jamais avec chaque page.
            'quickAdd' => Inertia::optional(fn (): ?array => $member ? $this->quickAdd($user, $active) : null),
            'campus' => [
                'development' => $development,
                'quickAddAvailable' => $member,
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
                'contexts' => $member
                    ? $available->map(fn (Membership $membership): array => [
                        'membershipId' => $membership->id,
                        'short' => $contexts->label($membership).($sameName[$contexts->label($membership)] > 1 ? ' · '.Membership::ROLE_LABELS[$membership->role] : ''),
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

    /**
     * Ce qu'il faut pour ajouter en un geste un cours, une tâche ou une note dans son espace individuel : fuseau, matières et période en cours du seul compte connecté.
     *
     * @return array{timezone: string, today: string, individual: bool, subjects: list<array{id: int, name: string}>, periodId: int|null}
     */
    private function quickAdd(User $user, ?Membership $active): array
    {
        $timezone = $user->personalSpace?->timezone ?? (string) config('app.display_timezone');
        $today = now($timezone)->toDateString();

        return [
            'timezone' => $timezone, 'today' => $today,
            // Un cours ajouté ici va au planning personnel : il n'est proposé que dans l'espace individuel.
            'individual' => $active === null,
            'subjects' => PersonalSubject::query()->where('owner_id', $user->id)->whereNull('archived_at')->orderBy('name')->limit(200)->get(['id', 'name'])
                ->map(fn (PersonalSubject $subject): array => ['id' => $subject->id, 'name' => $subject->name])->all(),
            'periodId' => PersonalPeriod::query()->where('owner_id', $user->id)->whereNull('archived_at')->where('starts_on', '<=', now()->toDateString())->where('ends_on', '>=', now()->toDateString())->orderBy('starts_on')->value('id'),
        ];
    }
}
