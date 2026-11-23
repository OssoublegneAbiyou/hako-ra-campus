<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Invitation;
use App\Models\Membership;
use App\Services\ActiveContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PlatformController extends Controller
{
    public function index(Request $request, ActiveContext $context): Response
    {
        $acting = $context->require($request, 'platform_admin');
        Gate::authorize('viewAny', [Institution::class, $acting]);
        $institutions = Institution::query()->whereNull('archived_at')->orderBy('name')->simplePaginate(20);
        $ids = collect($institutions->items())->pluck('id');
        $years = AcademicYear::query()->whereNull('archived_at')->whereIn('institution_id', $ids)->orderBy('starts_on')->get()->groupBy('institution_id');
        $admins = Membership::query()->where('role', 'institution_admin')->where('status', '!=', 'closed')
            ->whereIn('institution_id', $ids)->with('user:id,name')->get()->groupBy('institution_id');
        $person = fn (Membership $membership): array => ['id' => $membership->id, 'name' => $membership->user->name];
        $pending = fn (Invitation $invitation): array => [
            'id' => $invitation->id, 'email' => $invitation->email, 'status' => $invitation->displayStatus(),
            'institution' => $invitation->institution?->name, 'candidate' => $invitation->candidate?->name,
            'expiresAt' => $invitation->expires_at->toIso8601String(),
        ];
        $openInvitations = Invitation::query()->whereIn('status', ['pending', 'pending_review'])
            ->with(['institution:id,name', 'candidate:id,name'])->latest('id');

        return Inertia::render('admin/Platform', [
            'institutions' => collect($institutions->items())->map(fn (Institution $institution): array => [
                'id' => $institution->id,
                'code' => $institution->code,
                'name' => $institution->name,
                'years' => ($years[$institution->id] ?? collect())->map(fn (AcademicYear $year): array => ['id' => $year->id, 'name' => $year->name])->values()->all(),
                'admins' => ($admins[$institution->id] ?? collect())->map($person)->values()->all(),
            ])->all(),
            'previousHref' => $institutions->previousPageUrl(),
            'nextHref' => $institutions->nextPageUrl(),
            'currentPage' => $institutions->currentPage(),
            'editors' => Membership::query()->where('role', 'catalogue_editor')->where('status', '!=', 'closed')
                ->with('user:id,name')->limit(50)->get()->map($person)->all(),
            'adminInvitations' => (clone $openInvitations)->where('role', 'institution_admin')->limit(50)->get()->map($pending)->all(),
            'editorInvitations' => (clone $openInvitations)->where('role', 'catalogue_editor')->limit(50)->get()->map($pending)->all(),
        ]);
    }

    /**
     * Création d'un établissement avec sa première année : réservée à l'administration de la plateforme.
     */
    public function storeInstitution(Request $request, ActiveContext $context): RedirectResponse
    {
        $acting = $context->require($request, 'platform_admin');
        Gate::authorize('create', [Institution::class, $acting]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/', 'unique:institutions,code'],
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
            'year_name' => ['required', 'string', 'max:255'],
            'year_code' => ['required', 'string', 'max:40'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
        ]);

        DB::transaction(function () use ($data, $request): void {
            $institution = Institution::create(['code' => strtoupper($data['code']), 'name' => $data['name'], 'timezone' => $data['timezone']]);
            AcademicYear::create([
                'institution_id' => $institution->id, 'code' => $data['year_code'], 'name' => $data['year_name'],
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
            ]);
            AuditLog::record($request->user(), 'institution.created', $institution, $institution->id);
        });

        return back()->with('status', 'Établissement « '.$data['name'].' » créé. Invitez maintenant son administration.');
    }
}
