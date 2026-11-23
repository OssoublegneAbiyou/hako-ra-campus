<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Invitation;
use App\Models\Membership;
use App\Services\ActiveContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InstitutionController extends Controller
{
    private const STATUS_LABELS = ['active' => 'Active', 'suspended' => 'Suspendue'];

    public function index(Request $request, ActiveContext $context): Response
    {
        $acting = $context->require($request, 'institution_admin');
        Gate::authorize('viewAny', [Membership::class, $acting]);
        $members = $this->members($acting)->with('user:id,name')->orderBy('role')->orderBy('id')->simplePaginate(20);

        return Inertia::render('admin/Institution', [
            'institution' => $acting->institution->name,
            'year' => $acting->academicYear->name,
            'members' => collect($members->items())->map(fn (Membership $member): array => [
                'id' => $member->id,
                'name' => $member->user->name,
                'role' => Membership::ROLE_LABELS[$member->role],
                'localIdentifier' => $member->local_identifier,
                'status' => self::STATUS_LABELS[$member->status],
                'revocable' => $member->user_id !== $request->user()->id && in_array($member->role, ['learner', 'teacher'], true),
            ])->all(),
            'previousHref' => $members->previousPageUrl(),
            'nextHref' => $members->nextPageUrl(),
            'currentPage' => $members->currentPage(),
            'invitations' => Invitation::query()->where('institution_id', $acting->institution_id)
                ->where('academic_year_id', $acting->academic_year_id)->whereIn('role', ['learner', 'teacher'])
                ->whereIn('status', ['pending', 'pending_review'])->with(['group:id,name', 'candidate:id,name'])
                ->latest('id')->limit(50)->get()->map(fn (Invitation $invitation): array => [
                    'id' => $invitation->id,
                    'role' => Membership::ROLE_LABELS[$invitation->role],
                    'email' => $invitation->email,
                    'localIdentifier' => $invitation->local_identifier,
                    'group' => $invitation->group?->name,
                    'status' => $invitation->displayStatus(),
                    'expiresAt' => $invitation->expires_at->toIso8601String(),
                    'reviewReason' => $invitation->review_reason,
                    'candidate' => $invitation->candidate?->name,
                ])->all(),
            'groups' => Group::query()->whereNull('archived_at')->where('institution_id', $acting->institution_id)
                ->where('academic_year_id', $acting->academic_year_id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Group $group): array => ['id' => $group->id, 'name' => $group->name])->all(),
            'accountsHref' => route('institution.accounts.index', $acting->id, absolute: false),
        ]);
    }

    /**
     * Export limité au périmètre de l'appartenance active ; aucun paramètre de la requête ne l'élargit.
     */
    public function export(Request $request, ActiveContext $context): StreamedResponse
    {
        $acting = $context->require($request, 'institution_admin');
        Gate::authorize('viewAny', [Membership::class, $acting]);
        AuditLog::record($request->user(), 'members.exported', null, $acting->institution_id);
        $query = $this->members($acting)->with('user:id,name')->orderBy('id');

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Nom', 'Rôle', 'Matricule', 'Statut', 'Depuis'], ';', '"', '');
            $query->chunkById(500, function ($members) use ($output): void {
                foreach ($members as $member) {
                    fputcsv($output, array_map($this->safeCell(...), [
                        $member->user->name, Membership::ROLE_LABELS[$member->role], (string) $member->local_identifier,
                        self::STATUS_LABELS[$member->status], $member->starts_at->format('Y-m-d'),
                    ]), ';', '"', '');
                }
            });
            fclose($output);
        }, 'membres.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }

    private function members(Membership $acting): Builder
    {
        return Membership::query()->where('scope', 'institution')->where('institution_id', $acting->institution_id)
            ->where('academic_year_id', $acting->academic_year_id)->where('status', '!=', 'closed');
    }

    /**
     * Neutralise une cellule qu'un tableur interpréterait comme une formule.
     */
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
