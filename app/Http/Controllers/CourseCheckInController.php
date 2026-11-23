<?php

namespace App\Http\Controllers;

use App\Models\CourseCheckIn;
use App\Services\Attachments;
use App\Services\CourseCheckIns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Retours privés après cours : chaque route ne lit et n'écrit que les retours du compte connecté.
 * Il n'existe aucune route désignant le retour d'une autre personne.
 */
class CourseCheckInController extends Controller
{
    public function index(Request $r, CourseCheckIns $checkIns): Response
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);
        $history = CourseCheckIn::query()->where('owner_id', $r->user()->id)->orderByDesc('course_ends_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return Inertia::render('CheckIns', [
            'pending' => $checkIns->pending($r->user()),
            'pendingDays' => (int) config('campus.check_ins.pending_days'),
            'history' => $history->getCollection()->map(fn (CourseCheckIn $checkIn): array => [
                'occurrenceId' => $checkIn->course_occurrence_id, 'title' => $checkIn->course_title, 'startsAt' => $checkIn->course_starts_at->utc()->toIso8601String(),
                'status' => $checkIn->status, 'statusLabel' => CourseCheckIn::STATUS_LABELS[$checkIn->status],
            ])->all(),
            'pagination' => ['page' => $history->currentPage(), 'total' => $history->lastPage(), 'previous' => $history->previousPageUrl(), 'next' => $history->nextPageUrl()],
        ]);
    }

    public function show(Request $r, CourseCheckIns $checkIns, Attachments $attachments, string $occurrence): Response
    {
        $course = $checkIns->course($r->user(), $occurrence);
        $checkIn = CourseCheckIn::query()->where('owner_id', $r->user()->id)->where('course_occurrence_id', $occurrence)->with('task:id,title,source_check_in_id')->first();
        abort_if($course === null && $checkIn === null, 404);
        $started = $course !== null && ! $course['starts_at']->isFuture();

        return Inertia::render('CheckIn', [
            'occurrenceId' => $occurrence,
            'course' => [
                'title' => $course['title'] ?? $checkIn->course_title,
                'startsAt' => ($course['starts_at'] ?? $checkIn->course_starts_at)->utc()->toIso8601String(),
                'endsAt' => ($course['ends_at'] ?? $checkIn->course_ends_at)->utc()->toIso8601String(),
                'timezone' => $course['timezone'] ?? $r->user()->personalSpace?->timezone ?? config('app.display_timezone'),
                'cancelled' => ($course['status'] ?? 'active') === 'cancelled',
                'provenance' => $course['provenance'] ?? null,
                'stillAccessible' => $course !== null,
            ],
            'checkIn' => $checkIn ? [
                'version' => $checkIn->lock_version, 'status' => $checkIn->status, 'statusLabel' => CourseCheckIn::STATUS_LABELS[$checkIn->status],
                'summary' => $checkIn->summary, 'workToDo' => $checkIn->work_to_do, 'pointsToReview' => $checkIn->points_to_review,
                'understanding' => $checkIn->understanding, 'task' => $checkIn->task ? ['title' => $checkIn->task->title] : null,
                'updatedAt' => $checkIn->updated_at->utc()->toIso8601String(),
            ] : null,
            'canWrite' => $checkIn !== null || ($started && $course['status'] === 'active'),
            'notStarted' => $course !== null && ! $started,
            'understandingLabels' => CourseCheckIn::UNDERSTANDING_LABELS,
            'attachments' => $checkIn ? $attachments->forCheckIn($r->user(), $checkIn) : [],
            'attachmentLimits' => $attachments->limits($r->user()),
        ]);
    }

    /**
     * Contrat rejouable : expected_version vaut 0 à la création, puis la version connue. Réponse JSON si elle est demandée (synchronisation du lot 20).
     */
    public function update(Request $r, CourseCheckIns $checkIns, string $occurrence): RedirectResponse|JsonResponse
    {
        $data = $r->validate([
            'action' => 'required|in:save,nothing,defer,ignore', 'expected_version' => 'required|integer|min:0',
            'summary' => 'nullable|string|max:2000', 'work_to_do' => 'nullable|string|max:2000', 'points_to_review' => 'nullable|string|max:2000',
            'understanding' => 'nullable|integer|between:1,4', 'create_task' => 'sometimes|boolean', 'task_title' => 'nullable|string|max:255',
        ]);
        $result = $checkIns->save($r->user(), $occurrence, $data);
        $checkIn = $result['checkIn'];
        if ($r->expectsJson()) {
            return response()->json(['id' => $checkIn->id, 'occurrence_id' => $checkIn->course_occurrence_id, 'version' => $checkIn->lock_version,
                'status' => $checkIn->status, 'task_id' => $result['task']?->id, 'replayed' => $result['replayed']], 200, ['Cache-Control' => 'no-store, private']);
        }

        return redirect()->route('check-ins.index')->with('status', match ($checkIn->status) {
            'completed' => 'Retour enregistré. Il reste privé : vous seul pouvez le lire.'.($result['task'] ? ' Tâche « '.$result['task']->title.' » enregistrée.' : ''),
            'nothing' => 'Rien à signaler : c’est noté.',
            'deferred' => 'Retour reporté : il reste proposé dans vos retours à renseigner.',
            default => 'Ce cours ne vous sera plus proposé. Vous pouvez encore le renseigner depuis le planning.',
        });
    }
}
