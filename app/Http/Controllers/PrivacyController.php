<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\PrivacyRequest;
use App\Services\AccountEraser;
use App\Services\DataExporter;
use App\Services\RetentionPolicies;
use App\Support\MinorsPathway;
use App\Support\PrivacyNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Confidentialité : notice publique versionnée, et, pour le compte connecté, ses propres choix, demandes, exports et la suppression de son compte.
 * Aucune route ne désigne les données d'une autre personne.
 */
class PrivacyController extends Controller
{
    public function notice(Request $r, RetentionPolicies $policies): Response
    {
        $version = $r->validate(['version' => 'sometimes|string|max:20'])['version'] ?? null;
        $notice = $version !== null ? PrivacyNotice::find($version) : PrivacyNotice::current();
        abort_if($notice === null, 404);

        return Inertia::render('Privacy', [
            'notice' => $notice, 'current' => PrivacyNotice::current()['version'], 'history' => PrivacyNotice::history(),
            'durations' => $policies->durations(), 'minorsOpen' => MinorsPathway::isOpen(),
        ]);
    }

    public function settings(Request $r, AccountEraser $eraser): Response
    {
        $user = $r->user();
        $notice = PrivacyNotice::current();
        $label = fn (PrivacyRequest $request): array => ['id' => $request->id, 'type' => $request->type, 'typeLabel' => PrivacyRequest::TYPES[$request->type], 'status' => $request->status,
            'statusLabel' => PrivacyRequest::STATUSES[$request->status], 'message' => $request->message, 'response' => $request->response, 'createdAt' => $request->created_at->utc()->toIso8601String(),
            'closedAt' => $request->closed_at?->utc()->toIso8601String(), 'cancellable' => in_array($request->status, PrivacyRequest::OPEN, true) && in_array($request->type, ['rectification', 'objection'], true)];

        return Inertia::render('PrivacySettings', [
            'notice' => ['version' => $notice['version'], 'publishedOn' => $notice['publishedOn'], 'toComplete' => $notice['toComplete'],
                'acknowledgedAt' => DB::table('privacy_acknowledgements')->where('user_id', $user->id)->where('notice_version', $notice['version'])->value('created_at')],
            'requests' => PrivacyRequest::query()->where('user_id', $user->id)->orderByDesc('created_at')->limit(50)->get()->map($label)->all(),
            'exports' => DataExport::query()->where('user_id', $user->id)->orderByDesc('created_at')->limit(10)->get()->map(fn (DataExport $export): array => [
                'id' => $export->id, 'createdAt' => $export->created_at->utc()->toIso8601String(), 'expiresAt' => $export->expires_at->utc()->toIso8601String(), 'sizeBytes' => $export->size_bytes,
                'available' => $export->file_removed_at === null && $export->expires_at->isFuture(), 'downloads' => $export->downloads])->all(),
            'exportDays' => max(1, (int) config('campus.privacy.export_ttl_days')),
            'erasureBlocker' => $eraser->blocker($user),
            'types' => ['rectification' => PrivacyRequest::TYPES['rectification'], 'objection' => PrivacyRequest::TYPES['objection']],
        ]);
    }

    /**
     * Prise de connaissance de la notice en vigueur : preuve datée, liée à la version et à l'empreinte du texte présenté.
     */
    public function acknowledge(Request $r): RedirectResponse
    {
        $data = $r->validate(['version' => 'required|string|max:20']);
        $notice = PrivacyNotice::current();
        if ($data['version'] !== $notice['version']) {
            throw ValidationException::withMessages(['version' => 'Une version plus récente de la notice a été publiée. Relisez-la avant de confirmer.']);
        }
        DB::table('privacy_acknowledgements')->insertOrIgnore(['user_id' => $r->user()->id, 'notice_version' => $notice['version'], 'notice_hash' => $notice['hash'], 'created_at' => now()]);

        return back()->with('status', 'Prise de connaissance de la notice du '.$notice['publishedOn'].' enregistrée.');
    }

    /**
     * Demande de rectification ou d'opposition : enregistrée et suivie. L'identité est celle de la session ; aucune pièce n'est demandée.
     */
    public function storeRequest(Request $r): RedirectResponse
    {
        $data = $r->validate(['type' => 'required|in:rectification,objection', 'message' => 'required|string|min:10|max:2000']);
        if (PrivacyRequest::query()->where('user_id', $r->user()->id)->whereIn('status', PrivacyRequest::OPEN)->count() >= 5) {
            throw ValidationException::withMessages(['message' => 'Vous avez déjà cinq demandes en cours. Attendez leur traitement ou annulez-en une.']);
        }
        $request = PrivacyRequest::create(['user_id' => $r->user()->id, 'type' => $data['type'], 'message' => trim($data['message'])]);
        AuditLog::record($r->user(), 'privacy.request.created', null, null, null, ['type' => $request->type]);

        return back()->with('status', 'Demande enregistrée. Vous pouvez suivre son état sur cette page.');
    }

    public function cancelRequest(Request $r, string $request): RedirectResponse
    {
        $found = PrivacyRequest::query()->where('user_id', $r->user()->id)->whereIn('type', ['rectification', 'objection'])->whereIn('status', PrivacyRequest::OPEN)->findOrFail($request);
        $found->update(['status' => 'cancelled', 'closed_at' => now()]);

        return back()->with('status', 'Demande annulée.');
    }

    /**
     * Export : mot de passe confirmé récemment (middleware), fichier privé, disponible pendant la durée paramétrée.
     */
    public function storeExport(Request $r, DataExporter $exporter): RedirectResponse
    {
        $user = $r->user();
        if (DataExport::query()->where('user_id', $user->id)->where('created_at', '>', now()->subDay())->count() >= 3) {
            throw ValidationException::withMessages(['export' => 'Trois exports ont déjà été générés depuis vingt-quatre heures. Téléchargez le dernier, ou réessayez demain.']);
        }
        DB::transaction(function () use ($user, $exporter): void {
            $request = PrivacyRequest::create(['user_id' => $user->id, 'type' => 'access', 'status' => 'completed', 'identity_verified_at' => now(), 'closed_at' => now()]);
            $exporter->create($user, $request);
        });

        return redirect()->route('privacy.settings')->with('status', 'Export prêt. Il reste téléchargeable '.max(1, (int) config('campus.privacy.export_ttl_days')).' jours, par vous seul.');
    }

    public function downloadExport(Request $r, DataExporter $exporter, string $export): StreamedResponse
    {
        $found = $exporter->readable($r->user(), $export);
        // Export inconnu, d'un autre compte, expiré ou retiré : même réponse, sans indice.
        abort_if($found === null, 404);
        $found->forceFill(['downloads' => $found->downloads + 1, 'last_downloaded_at' => now()])->save();

        return Storage::disk('local')->download($exporter->path($found), 'campus-mes-donnees-'.$found->created_at->format('Y-m-d').'.json', [
            'Content-Type' => 'application/json', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Écran de suppression : conséquences, ce qui est effacé, ce que l'établissement conserve, et rappel de l'export.
     */
    public function deletion(Request $r, AccountEraser $eraser): Response
    {
        $user = $r->user();

        return Inertia::render('AccountDeletion', [
            'blocker' => $eraser->blocker($user),
            'memberships' => $user->memberships()->effective()->with('institution:id,name')->get()->map(fn ($membership) => $membership->institution?->name ?? 'Plateforme HAKO-RA Campus')->unique()->values()->all(),
            'hasRecentExport' => DataExport::query()->where('user_id', $user->id)->whereNull('file_removed_at')->where('expires_at', '>', now())->exists(),
            'backupDays' => max(1, (int) config('campus.retention.backup_days')),
            'confirmationWord' => 'SUPPRIMER',
        ]);
    }

    /**
     * Suppression du compte : mot de passe ressaisi dans cette demande même, et mot de confirmation. Ensuite la session est fermée.
     */
    public function destroy(Request $r, AccountEraser $eraser): RedirectResponse
    {
        $r->validate(['password' => 'required|current_password', 'confirmation' => 'required|in:SUPPRIMER'], [
            'password.current_password' => 'Mot de passe incorrect.', 'confirmation.in' => 'Saisissez le mot SUPPRIMER pour confirmer.',
        ]);
        $user = $r->user();
        DB::transaction(function () use ($user, $eraser): void {
            // La demande est tracée avant l'effacement : type, dates et état restent comme preuve de traitement, sans texte.
            PrivacyRequest::create(['user_id' => $user->id, 'type' => 'erasure', 'status' => 'completed', 'identity_verified_at' => now(), 'closed_at' => now()]);
            $eraser->erase($user);
        });
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('privacy.notice')->with('status', 'Votre compte est supprimé. Votre espace individuel est effacé et vos accès sont fermés.');
    }
}
