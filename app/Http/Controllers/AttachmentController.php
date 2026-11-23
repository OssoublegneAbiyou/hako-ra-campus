<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\AttachmentReport;
use App\Models\CourseCheckIn;
use App\Models\Membership;
use App\Models\Task;
use App\Services\ActiveContext;
use App\Services\Attachments;
use App\Services\ContextVisibility;
use App\Support\PrivilegedAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dépôt, téléchargement, suppression et signalement des pièces jointes. Tout téléchargement passe par ce contrôleur :
 * il n'existe ni lien public ni adresse temporaire, et connaître l'identifiant d'un fichier ne donne aucun droit.
 */
class AttachmentController extends Controller
{
    public function __construct(private Attachments $attachments) {}

    public function storeForTask(Request $r, string $task): RedirectResponse|JsonResponse
    {
        $data = $this->validated($r);
        // Le devoir retiré ou la tâche en corbeille sont introuvables ; le droit précis est vérifié dans la transaction de dépôt.
        $parent = Task::query()->whereNull('deleted_at')->findOrFail($task);

        return $this->answer($r, $this->attachments->store($r->user(), $parent, $r->file('file'), $data['visibility'] ?? 'personal', $data['client_key']));
    }

    public function storeForCheckIn(Request $r, string $occurrence): RedirectResponse|JsonResponse
    {
        $data = $this->validated($r);
        $parent = CourseCheckIn::query()->where('owner_id', $r->user()->id)->where('course_occurrence_id', $occurrence)->firstOrFail();

        return $this->answer($r, $this->attachments->store($r->user(), $parent, $r->file('file'), 'personal', $data['client_key']));
    }

    /**
     * Téléchargement autorisé. Les en-têtes forcent l'enregistrement du fichier et interdisent toute interprétation ou exécution sur le domaine.
     */
    public function download(Request $r, string $attachment): StreamedResponse
    {
        $file = $this->attachments->downloadable($r->user(), $attachment);
        $fallback = 'piece-jointe.'.$file->extension;

        return Storage::disk('local')->download($file->path(), $file->display_name, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => 'attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($file->display_name),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $r, string $attachment): RedirectResponse|JsonResponse
    {
        $deleted = $this->attachments->delete($r->user(), $attachment);
        if ($r->expectsJson()) {
            return response()->json(['id' => $deleted->id, 'deleted' => true], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Fichier supprimé. L’espace correspondant est de nouveau disponible.');
    }

    public function report(Request $r, string $attachment): RedirectResponse|JsonResponse
    {
        $data = $r->validate(['reason' => 'required|string|min:5|max:500']);
        $report = $this->attachments->report($r->user(), $attachment, $data['reason']);
        if ($r->expectsJson()) {
            return response()->json(['id' => $report->id, 'status' => $report->status], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Signalement transmis à l’administration de l’établissement.');
    }

    public function reports(Request $r, ActiveContext $context, ContextVisibility $visibility): Response
    {
        $admin = $this->admin($r, $context, $visibility);
        $list = AttachmentReport::query()->where('institution_id', $admin->institution_id)->with('attachment:id,display_name,size_bytes,scan_status,withdrawn_at,deleted_at')
            ->orderByRaw("status = 'open' DESC")->orderByDesc('id')->paginate(30)->withQueryString();

        return Inertia::render('AttachmentReports', [
            'reports' => $list->getCollection()->map(fn (AttachmentReport $report): array => [
                'id' => $report->id, 'reason' => $report->reason, 'status' => $report->status, 'createdAt' => $report->created_at->utc()->toIso8601String(),
                'decisionNote' => $report->decision_note, 'attachmentId' => $report->attachment_id, 'fileName' => $report->attachment->display_name,
                'downloadable' => $report->attachment->scan_status === 'clean' && $report->attachment->deleted_at === null,
                'withdrawn' => $report->attachment->withdrawn_at !== null, 'deleted' => $report->attachment->deleted_at !== null,
            ])->all(),
            'pagination' => ['page' => $list->currentPage(), 'total' => $list->lastPage(), 'previous' => $list->previousPageUrl(), 'next' => $list->nextPageUrl()],
        ]);
    }

    public function decide(Request $r, ActiveContext $context, ContextVisibility $visibility, int $report): RedirectResponse
    {
        $data = $r->validate(['decision' => 'required|in:upheld,dismissed', 'note' => 'nullable|string|max:500']);
        $decided = $this->attachments->decide($r->user(), $this->admin($r, $context, $visibility), $report, $data['decision'], $data['note'] ?? null);

        return back()->with('status', $decided->status === 'upheld' ? 'Fichier retiré de la diffusion.' : 'Signalement classé sans suite.');
    }

    private function admin(Request $r, ActiveContext $context, ContextVisibility $visibility): Membership
    {
        $membership = $context->current($r);
        abort_unless($membership !== null && $membership->scope === 'institution' && $membership->role === 'institution_admin' && PrivilegedAccess::available(), 403);

        return $visibility->administeredMembership($r->user(), $membership->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $r): array
    {
        // Les contrôles de contenu (type réel, signature, contenu actif) sont faits ensuite par Attachments::inspect.
        return $r->validate(['client_key' => 'required|uuid', 'visibility' => 'sometimes|in:personal,group',
            'file' => 'required|file|max:'.(int) config('campus.attachments.max_file_kilobytes')], ['file.max' => 'Ce fichier dépasse la taille autorisée.', 'file.uploaded' => 'Ce fichier dépasse la taille autorisée ou n’a pas pu être reçu.']);
    }

    /**
     * @param  array{attachment: Attachment, replayed: bool}  $result
     */
    private function answer(Request $r, array $result): RedirectResponse|JsonResponse
    {
        $attachment = $result['attachment'];
        if ($r->expectsJson()) {
            return response()->json(['id' => $attachment->id, 'status' => $attachment->fresh()->scan_status, 'replayed' => $result['replayed']], 200, ['Cache-Control' => 'no-store, private']);
        }

        return back()->with('status', 'Fichier reçu. Il sera disponible après l’analyse antivirus.');
    }
}
