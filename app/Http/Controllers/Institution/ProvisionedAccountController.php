<?php

namespace App\Http\Controllers\Institution;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\ContextVisibility;
use App\Support\MinorsPathway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProvisionedAccountController extends Controller
{
    public function index(Request $request, ContextVisibility $visibility, int $membership): Response
    {
        $admin = $visibility->administeredMembership($request->user(), $membership);
        $accounts = $visibility->provisionedLearners($admin)->orderBy('name')->simplePaginate(20);

        return Inertia::render('institution/ProvisionedAccounts', [
            'membershipId' => $admin->id,
            'institution' => $admin->institution->name,
            'minorsPathwayOpen' => MinorsPathway::isOpen(),
            'identityChecks' => AccountProvisioner::IDENTITY_CHECKS,
            'secretTtlHours' => (int) config('campus.identity.activation_ttl_hours'),
            'accounts' => collect($accounts->items())->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'loginIdentifier' => $user->login_identifier,
                'localIdentifier' => Membership::query()->where('user_id', $user->id)
                    ->where('institution_id', $admin->institution_id)->where('academic_year_id', $admin->academic_year_id)
                    ->where('role', 'learner')->where('status', '!=', 'closed')->value('local_identifier'),
                'state' => $user->activated_at ? 'active'
                    : ($user->activation_expires_at?->isFuture() ? 'pending' : 'expired'),
            ])->all(),
            'previousHref' => $accounts->previousPageUrl(),
            'nextHref' => $accounts->nextPageUrl(),
            'currentPage' => $accounts->currentPage(),
        ]);
    }

    public function store(Request $request, AccountProvisioner $provisioner, int $membership): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'local_identifier' => ['required', 'string', 'max:100'],
            'adult_attested' => ['boolean'],
        ]);
        $result = $provisioner->provision($request->user(), $membership, $data['name'], $data['local_identifier'], $request->boolean('adult_attested'));

        return $this->backWithSecret($membership, $result);
    }

    public function recover(Request $request, AccountProvisioner $provisioner, int $membership, int $user): RedirectResponse
    {
        $data = $request->validate([
            'identity_check' => ['required', 'string'],
            'identity_confirmed' => ['accepted'],
        ], ['identity_confirmed.accepted' => 'Confirmez avoir contrôlé vous-même l’identité de la personne.']);
        $result = $provisioner->issueRecoverySecret($request->user(), $membership, $user, $data['identity_check']);

        return $this->backWithSecret($membership, $result);
    }

    /**
     * Le secret transite une seule fois par la session chiffrée de l'administrateur ; il n'est ni stocké en clair ni journalisé.
     *
     * @param  array{user: User, secret: string}  $result
     */
    private function backWithSecret(int $membership, array $result): RedirectResponse
    {
        return redirect()->route('institution.accounts.index', $membership)->with('oneTimeSecret', [
            'name' => $result['user']->name,
            'identifier' => $result['user']->login_identifier,
            'secret' => $result['secret'],
        ]);
    }
}
