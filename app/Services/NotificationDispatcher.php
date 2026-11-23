<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\NotificationDelivery;
use App\Models\Opportunity;
use App\Models\PushSubscription;
use App\Models\SavedSearch;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\CampusReminder;
use App\Support\Vapid;
use App\Support\WebPush;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Remise des rappels arrivés à échéance et envoi sur les canaux externes.
 * Juste avant chaque remise et chaque envoi, le compte, les droits, les préférences et la validité de l'objet sont recontrôlés.
 * Un envoi est réservé (« en traitement ») avant l'appel au fournisseur : un rejeu ne le reprend jamais, et un envoi
 * interrompu ou sans réponse devient « indéterminé » au lieu d'être renvoyé à l'aveugle.
 */
class NotificationDispatcher
{
    public function __construct(private NotificationStore $store, private NotificationPreferences $preferences, private ContextVisibility $visibility,
        private Tasks $tasks, private CourseCheckIns $checkIns, private OpportunityFollowUps $followUps, private SavedSearches $savedSearches) {}

    /**
     * Canaux effectifs d'un rappel : les préférences de sa catégorie, restreintes pour une synthèse de recherche aux canaux
     * choisis sur cette recherche, relus maintenant.
     *
     * @return array{enabled: bool, email: bool, push: bool}
     */
    private function channels(UserNotification $notification): array
    {
        $channels = $this->preferences->category($notification->user_id, $notification->category);
        if ($notification->type === 'search.digest') {
            $search = SavedSearch::query()->whereKey($notification->context['saved_search_id'] ?? 0)->where('user_id', $notification->user_id)->first();
            $channels['email'] = $channels['email'] && (bool) $search?->email;
            $channels['push'] = $channels['push'] && (bool) $search?->push;
        }

        return $channels;
    }

    /**
     * Remet dans le centre interne les rappels arrivés à échéance et encore valides ; les autres expirent ou sont annulés.
     *
     * @return array{delivered: int, cancelled: int, expired: int}
     */
    public function release(?CarbonImmutable $now = null): array
    {
        $now ??= now()->toImmutable();
        $this->preferences->forget();
        $batch = max(1, (int) config('campus.notifications.batch'));
        $totals = ['delivered' => 0, 'cancelled' => 0, 'expired' => 0];
        for ($round = 0; $round < 50; $round++) {
            $count = DB::transaction(function () use ($now, $batch, &$totals): int {
                $due = UserNotification::query()->where('status', 'scheduled')->where('scheduled_for', '<=', $now)->orderBy('id')->limit($batch)->lock('for update skip locked')->with('user')->get();
                foreach ($due as $notification) {
                    $totals[$this->settle($notification, $now)]++;
                }

                return $due->count();
            });
            if ($count < $batch) {
                break;
            }
        }

        return $totals;
    }

    private function settle(UserNotification $notification, CarbonImmutable $now): string
    {
        // Rappel périmé (panne, file en retard) : il expire, il n'est pas envoyé en retard.
        if ($notification->expires_at->lessThanOrEqualTo($now)) {
            $notification->forceFill(['status' => 'expired', 'status_reason' => 'too_late'])->save();

            return 'expired';
        }
        $reason = $this->invalid($notification);
        $channels = $this->channels($notification);
        if ($reason === null && ! $channels['enabled']) {
            $reason = 'preference';
        }
        if ($reason !== null) {
            $notification->forceFill(['status' => 'cancelled', 'status_reason' => $reason])->save();

            return 'cancelled';
        }
        $notification->forceFill(['status' => 'delivered', 'delivered_at' => $now])->save();
        // La plage silencieuse retarde les canaux externes ; le centre interne, lui, n'interrompt personne.
        $notBefore = $this->preferences->quietUntil($notification->user_id, $notification->category, $now) ?? $now;
        $targets = [];
        if ($channels['email'] && $this->preferences->emailConfigured() && $notification->user->email !== null && $notification->user->hasVerifiedEmail()) {
            $targets[] = ['channel' => 'email', 'push_subscription_id' => null, 'target_key' => 'email'];
        }
        if ($channels['push'] && Vapid::configured()) {
            foreach (PushSubscription::query()->where('user_id', $notification->user_id)->whereNull('revoked_at')->pluck('id') as $subscriptionId) {
                $targets[] = ['channel' => 'push', 'push_subscription_id' => $subscriptionId, 'target_key' => 'push:'.$subscriptionId];
            }
        }
        foreach ($targets as $target) {
            DB::table('notification_deliveries')->insertOrIgnore([...$target, 'user_notification_id' => $notification->id, 'status' => 'queued', 'attempts' => 0,
                'next_attempt_at' => $notBefore->utc()->format('Y-m-d H:i:s'), 'created_at' => $now, 'updated_at' => $now]);
        }

        return 'delivered';
    }

    /**
     * Envoie les messages externes en file. Chaque envoi est d'abord réservé dans une transaction courte, puis traité hors transaction.
     *
     * @return array{accepted: int, failed: int, uncertain: int, retried: int, cancelled: int, expired: int, deferred: int}
     */
    public function send(?CarbonImmutable $now = null): array
    {
        $now ??= now()->toImmutable();
        $this->preferences->forget();
        $totals = ['accepted' => 0, 'failed' => 0, 'uncertain' => 0, 'retried' => 0, 'cancelled' => 0, 'expired' => 0, 'deferred' => 0];
        // Envoi réservé puis jamais conclu (processus interrompu) : on ne sait pas s'il est parti. Il est tracé comme indéterminé, pas renvoyé.
        $totals['uncertain'] += NotificationDelivery::query()->where('status', 'processing')
            ->where('claimed_at', '<=', $now->subMinutes((int) config('campus.notifications.processing_timeout_minutes')))
            ->update(['status' => 'uncertain', 'last_error' => 'worker_interrupted', 'finished_at' => $now]);
        $batch = max(1, (int) config('campus.notifications.batch'));
        for ($round = 0; $round < 50; $round++) {
            $claimed = DB::transaction(function () use ($now, $batch) {
                $ids = NotificationDelivery::query()->where('status', 'queued')->where('next_attempt_at', '<=', $now)->orderBy('id')->limit($batch)->lock('for update skip locked')->pluck('id');
                NotificationDelivery::query()->whereIn('id', $ids)->update(['status' => 'processing', 'claimed_at' => $now, 'attempts' => DB::raw('attempts + 1')]);

                return $ids;
            });
            foreach (NotificationDelivery::query()->whereIn('id', $claimed)->with(['notification.user', 'subscription'])->orderBy('id')->get() as $delivery) {
                $totals[$this->attempt($delivery, $now)]++;
            }
            if ($claimed->count() < $batch) {
                break;
            }
        }

        return $totals;
    }

    private function attempt(NotificationDelivery $delivery, CarbonImmutable $now): string
    {
        $notification = $delivery->notification;
        $finish = function (string $status, string $code) use ($delivery, $now): string {
            $delivery->forceFill(['status' => $status, 'last_error' => $status === 'accepted' ? null : $code, 'finished_at' => $now])->save();

            return $status;
        };
        if ($notification->status !== 'delivered') {
            return $finish('cancelled', 'notification_'.$notification->status);
        }
        if ($notification->expires_at->lessThanOrEqualTo($now)) {
            return $finish('expired', 'too_late');
        }
        // Recontrôle juste avant l'envoi : l'objet du rappel peut avoir changé depuis sa remise (tâche terminée, cours reporté, accès retiré).
        if (($reason = $this->invalid($notification)) !== null) {
            $this->store->cancelIds([$notification->id], $reason);

            return $finish('cancelled', $reason);
        }
        if (! $this->channels($notification)[$delivery->channel]) {
            return $finish('cancelled', 'preference');
        }
        if (($quiet = $this->preferences->quietUntil($notification->user_id, $notification->category, $now)) !== null) {
            $delivery->forceFill(['status' => 'queued', 'next_attempt_at' => $quiet, 'attempts' => $delivery->attempts - 1, 'claimed_at' => null])->save();

            return 'deferred';
        }

        if ($delivery->channel === 'email') {
            if (! $this->preferences->emailConfigured() || $notification->user->email === null) {
                return $finish('cancelled', 'channel_unavailable');
            }
            try {
                $notification->user->notifyNow(new CampusReminder($notification->title, url($notification->url)));
            } catch (Throwable) {
                // Le courriel n'offre aucune clé d'idempotence : après une erreur de transport, le message a pu partir. Pas de renvoi.
                return $finish('uncertain', 'mail_transport');
            }

            return $finish('accepted', 'mail');
        }

        $subscription = $delivery->subscription;
        if ($subscription === null || $subscription->revoked_at !== null || ! Vapid::configured()) {
            return $finish('cancelled', 'subscription_revoked');
        }
        $result = WebPush::send($subscription->endpoint, 'campus-'.$notification->id, max(60, $notification->expires_at->getTimestamp() - $now->getTimestamp()));
        switch ($result['outcome']) {
            case 'accepted':
                $subscription->forceFill(['last_accepted_at' => $now])->save();

                return $finish('accepted', $result['code']);
            case 'gone':
                // Le service de push annonce que l'abonnement n'existe plus : il est révoqué et ne sera plus jamais appelé.
                $subscription->forceFill(['revoked_at' => $now, 'revoked_reason' => 'endpoint_gone'])->save();

                return $finish('failed', $result['code']);
            case 'retry':
                $backoff = (array) config('campus.notifications.backoff_seconds');
                if ($delivery->attempts >= (int) config('campus.notifications.max_attempts')) {
                    return $finish('failed', 'retries_exhausted');
                }
                $delivery->forceFill(['status' => 'queued', 'last_error' => $result['code'], 'claimed_at' => null,
                    'next_attempt_at' => $now->addSeconds((int) ($backoff[$delivery->attempts - 1] ?? end($backoff)))])->save();

                return 'retried';
            case 'uncertain':
                return $finish('uncertain', $result['code']);
            default:
                return $finish('failed', $result['code']);
        }
    }

    /**
     * Motif pour lequel ce rappel ne doit plus être remis ni envoyé, ou null s'il reste valide maintenant.
     */
    private function invalid(UserNotification $notification): ?string
    {
        $user = User::query()->find($notification->user_id);
        if ($user === null || $user->status !== 'active') {
            return 'account_inactive';
        }
        $context = $notification->context ?? [];
        $member = fn (?string $role = null): bool => $this->visibility->usableMemberships($user)->where('scope', 'institution')
            ->where('institution_id', $context['institution_id'] ?? 0)->where('academic_year_id', $context['academic_year_id'] ?? 0)
            ->when($role !== null, fn ($query) => $query->where('role', $role))->exists();
        $enrolled = fn (): bool => Enrollment::query()->effective()->where('user_id', $user->id)->where('group_id', $context['group_id'] ?? 0)
            ->whereIn('membership_id', $this->visibility->usableMemberships($user)->where('role', 'learner')->select('id'))->exists();

        switch ($notification->type) {
            case 'course.checkin':
                $course = $this->checkIns->course($user, (string) $notification->subject_uuid);
                if ($course === null) {
                    return 'no_access';
                }
                if ($course['status'] !== 'active') {
                    return 'course_cancelled';
                }
                if ($course['ends_at']->getTimestamp() !== $notification->anchor_at?->getTimestamp()) {
                    return 'rescheduled';
                }

                return $this->checkIns->shouldRemind($user, (string) $notification->subject_uuid) ? null : 'settled';
            case 'task.due.d1': case 'task.due.d0':
                $task = $this->tasks->visible($user)->where('tasks.id', $notification->subject_uuid)->first();
                if ($task === null) {
                    return 'unavailable';
                }
                if ($task->due_at?->getTimestamp() !== $notification->anchor_at?->getTimestamp()) {
                    return 'rescheduled';
                }

                return $this->tasks->shouldRemind($user, $task->id) ? null : 'settled';
            case 'schedule.changed':
                return $member() ? null : 'no_access';
            case 'homework.withdrawn':
                return $enrolled() ? null : 'no_access';
            case 'homework.published': case 'homework.updated': case 'homework.restored':
                return $this->tasks->visible($user)->where('tasks.id', $notification->subject_uuid)->exists() ? null : 'unavailable';
            case 'grade.withdrawn':
                return $member('learner') ? null : 'no_access';
            case 'grade.published': case 'grade.corrected':
                if (! $member('learner')) {
                    return 'no_access';
                }

                $isEnrolled = $enrolled();

                return Assessment::query()->whereKey($notification->subject_uuid)->where('status', 'published')
                    ->when(! $isEnrolled, fn ($query) => $query->whereHas('results', fn ($result) => $result->where('user_id', $user->id)))
                    ->exists() ? null : 'unavailable';
            case 'search.digest':
                // Recherche supprimée ou arrêtée, ou plus aucune des fiches annoncées n'est publiée : la synthèse n'a plus lieu d'être.
                return $this->savedSearches->digestStillValid($user->id, $context['saved_search_id'] ?? null, $context['digest_key'] ?? null) ? null : 'unsubscribed';
            case 'opportunity.deadline': case 'opportunity.updated': case 'opportunity.resumed': case 'opportunity.suspended':
                $opportunity = Opportunity::query()->find($notification->subject_uuid);
                $expected = $notification->type === 'opportunity.suspended' ? 'suspended' : 'published';
                if ($opportunity === null || $opportunity->status !== $expected) {
                    return 'obsolete';
                }
                if ($notification->type === 'opportunity.deadline' && $opportunity->closes_at?->getTimestamp() !== $notification->anchor_at?->getTimestamp()) {
                    return 'rescheduled';
                }

                return $this->followUps->followers($opportunity->id)->contains($user->id) ? null : 'not_followed';
            case 'digest.daily':
                return $this->preferences->for($user)->digest_enabled ? null : 'preference';
            default:
                return null;
        }
    }
}
